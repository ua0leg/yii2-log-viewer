<?php

namespace Ua0leg\Yii2LogViewer;

/**
 * Reads Yii2 FileTarget logs from the end of the file without loading the whole file.
 *
 * Entry header format:
 * YYYY-MM-DD HH:MM:SS [ip][userId][sessionId][level][category] message
 *
 * Errors are usually followed by an [info][application] dump with $_GET / $_POST / $_SERVER.
 */
class LogReader
{
    private const HEADER_RE = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([^\]]*)\]\[([^\]]*)\]\[([^\]]*)\]\[([^\]]+)\]\[([^\]]+)\](?: (.*))?$/m';
    private const CHUNK_SIZE = 262144; // 256 KiB

    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path) && is_readable($this->path);
    }

    /**
     * @return array{size:int,mtime:int|false,mtimeFormatted:string,exists:bool}
     */
    public function meta(): array
    {
        if (!$this->exists()) {
            return [
                'exists' => false,
                'size' => 0,
                'mtime' => false,
                'mtimeFormatted' => '',
            ];
        }

        $mtime = filemtime($this->path);

        return [
            'exists' => true,
            'size' => (int)filesize($this->path),
            'mtime' => $mtime,
            'mtimeFormatted' => $mtime ? date('Y-m-d H:i:s', $mtime) : '',
        ];
    }

    /**
     * Read log events newest-first.
     *
     * @param array{
     *     limit?:int,
     *     before?:int|null,
     *     level?:string,
     *     q?:string,
     *     userId?:string,
     *     category?:string,
     *     uri?:string,
     *     excludeHttp?:bool,
     *     includeInfo?:bool
     * } $options
     * @return array{entries:array<int,array>,nextBefore:int|null,fileSize:int,scannedBytes:int,exhausted:bool}
     */
    public function read(array $options = []): array
    {
        $limit = max(1, min(200, (int)($options['limit'] ?? 50)));
        $before = isset($options['before']) && $options['before'] !== '' && $options['before'] !== null
            ? (int)$options['before']
            : null;
        $level = trim((string)($options['level'] ?? ''));
        $q = trim((string)($options['q'] ?? ''));
        $userId = trim((string)($options['userId'] ?? ''));
        $category = trim((string)($options['category'] ?? ''));
        $uri = trim((string)($options['uri'] ?? ''));
        $excludeHttp = !empty($options['excludeHttp']);
        $includeInfo = !empty($options['includeInfo']);

        if (!$this->exists()) {
            return [
                'entries' => [],
                'nextBefore' => null,
                'fileSize' => 0,
                'scannedBytes' => 0,
                'exhausted' => true,
            ];
        }

        $fp = fopen($this->path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open log file: ' . $this->path);
        }

        try {
            $fileSize = (int)fstat($fp)['size'];
            $cursor = $before === null ? $fileSize : min(max(0, $before), $fileSize);
            $matched = [];
            $pendingContext = null;
            $scanned = 0;
            $maxScan = min($fileSize, max(self::CHUNK_SIZE * 40, $limit * 50000)); // safety bound

            while ($cursor > 0 && count($matched) < $limit && $scanned < $maxScan) {
                $chunkEnd = $cursor;
                $chunkStart = max(0, $chunkEnd - self::CHUNK_SIZE);
                $length = $chunkEnd - $chunkStart;
                fseek($fp, $chunkStart);
                $data = fread($fp, $length);
                if ($data === false || $data === '') {
                    break;
                }
                $scanned += strlen($data);

                // Drop incomplete leading fragment unless at BOF
                $dataOffset = 0;
                if ($chunkStart > 0) {
                    if (!preg_match('/\n(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[)/', $data, $m, PREG_OFFSET_CAPTURE)) {
                        $cursor = $chunkStart;
                        continue;
                    }
                    $dataOffset = (int)$m[0][1] + 1; // after newline
                    $data = substr($data, $dataOffset);
                    $chunkStart += $dataOffset;
                }

                $rawEntries = $this->splitEntries($data, $chunkStart);
                // Walk newest → oldest within this chunk so we can attach context dumps
                for ($i = count($rawEntries) - 1; $i >= 0; $i--) {
                    $parsed = $this->parseEntry($rawEntries[$i]['text'], $rawEntries[$i]['offset']);
                    if ($parsed === null) {
                        continue;
                    }

                    if ($this->isApplicationContext($parsed)) {
                        $parsed = $this->enrichFromContextBody($parsed);
                        $pendingContext = $parsed;
                        if ($includeInfo && $this->passesFilters($parsed, $level, $q, $userId, $category, $uri, $excludeHttp)) {
                            $matched[] = $parsed;
                        }
                        continue;
                    }

                    // Context dump follows all messages of a request → attach to every older sibling of that request.
                    if ($pendingContext !== null) {
                        if ($this->sameRequest($parsed, $pendingContext)) {
                            $parsed = $this->mergeContext($parsed, $pendingContext);
                        } else {
                            $pendingContext = null;
                        }
                    }

                    if (!$includeInfo && $parsed['level'] === 'info' && $parsed['category'] === 'application') {
                        continue;
                    }

                    if ($this->passesFilters($parsed, $level, $q, $userId, $category, $uri, $excludeHttp)) {
                        $matched[] = $parsed;
                        if (count($matched) >= $limit) {
                            $cursor = $parsed['offset'];
                            break 2;
                        }
                    }
                }

                $cursor = $chunkStart;
            }

            $exhausted = $cursor <= 0 || $scanned >= $maxScan;

            return [
                'entries' => $matched,
                'nextBefore' => $exhausted ? null : $cursor,
                'fileSize' => $fileSize,
                'scannedBytes' => $scanned,
                'exhausted' => $exhausted,
            ];
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return array<int, array{text:string,offset:int}>
     */
    private function splitEntries(string $data, int $baseOffset): array
    {
        if ($data === '') {
            return [];
        }

        preg_match_all(
            '/^(?=\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[)/m',
            $data,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $starts = [];
        foreach ($matches[0] as $m) {
            $starts[] = (int)$m[1];
        }
        if ($starts === []) {
            return [];
        }

        $entries = [];
        $count = count($starts);
        for ($i = 0; $i < $count; $i++) {
            $start = $starts[$i];
            $end = $i + 1 < $count ? $starts[$i + 1] : strlen($data);
            $text = rtrim(substr($data, $start, $end - $start), "\r\n");
            if ($text === '') {
                continue;
            }
            $entries[] = [
                'text' => $text,
                'offset' => $baseOffset + $start,
            ];
        }

        return $entries;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseEntry(string $text, int $offset): ?array
    {
        if (!preg_match(self::HEADER_RE, $text, $m)) {
            return null;
        }

        $headerLineEnd = strpos($text, "\n");
        $body = $headerLineEnd === false ? '' : substr($text, $headerLineEnd + 1);
        $messageFirst = isset($m[7]) ? $m[7] : '';

        $fullMessage = $messageFirst;
        if ($body !== '') {
            $fullMessage = $messageFirst === '' ? $body : ($messageFirst . "\n" . $body);
        }

        $stack = null;
        if (preg_match('/^(.*?)(?:\nStack trace:\n)(.*)$/s', $fullMessage, $sm)) {
            $fullMessage = rtrim($sm[1]);
            $stack = rtrim($sm[2]);
        }

        $level = $m[5];
        $category = $m[6];

        return [
            'offset' => $offset,
            'datetime' => $m[1],
            'ip' => $m[2] === '-' ? null : $m[2],
            'userId' => ($m[3] === '-' || $m[3] === '') ? null : $m[3],
            'sessionId' => ($m[4] === '-' || $m[4] === '') ? null : $m[4],
            'level' => $level,
            'category' => $category,
            'message' => $fullMessage,
            'messageShort' => $this->shortMessage($fullMessage),
            'stack' => $stack,
            'raw' => $text,
            'requestUri' => null,
            'requestMethod' => null,
            'httpHost' => null,
            'referer' => null,
            'get' => null,
            'post' => null,
            'files' => null,
            'sessionUserId' => null,
            'exceptionClass' => $this->extractExceptionClass($fullMessage, $category),
            'httpStatus' => $this->extractHttpStatus($category),
        ];
    }

    private function isApplicationContext(array $entry): bool
    {
        return $entry['level'] === 'info'
            && $entry['category'] === 'application'
            && (
                strpos($entry['message'], '$_GET') !== false
                || strpos($entry['message'], '$_SERVER') !== false
            );
    }

    private function sameRequest(array $event, array $context): bool
    {
        if ($event['datetime'] !== $context['datetime']) {
            // same second is typical; allow 1s drift
            $t1 = strtotime($event['datetime']);
            $t2 = strtotime($context['datetime']);
            if ($t1 === false || $t2 === false || abs($t1 - $t2) > 1) {
                return false;
            }
        }

        if ($event['sessionId'] && $context['sessionId'] && $event['sessionId'] === $context['sessionId']) {
            return true;
        }

        if ($event['userId'] && $context['userId'] && $event['userId'] === $context['userId']
            && $event['ip'] && $context['ip'] && $event['ip'] === $context['ip']
        ) {
            return true;
        }

        // guest / missing ids — still pair by identical timestamp
        return $event['datetime'] === $context['datetime'];
    }

    private function mergeContext(array $event, array $context): array
    {
        foreach (['requestUri', 'requestMethod', 'httpHost', 'referer', 'get', 'post', 'files', 'sessionUserId'] as $key) {
            if ($context[$key] !== null && $event[$key] === null) {
                $event[$key] = $context[$key];
            }
        }
        $event['hasContext'] = true;

        return $event;
    }

    private function enrichFromContextBody(array $entry): array
    {
        $body = $entry['message'];

        if (preg_match("/'REQUEST_URI'\\s*=>\\s*'((?:\\\\'|[^'])*)'/", $body, $m)) {
            $entry['requestUri'] = stripcslashes($m[1]);
        }
        if (preg_match("/'REQUEST_METHOD'\\s*=>\\s*'((?:\\\\'|[^'])*)'/", $body, $m)) {
            $entry['requestMethod'] = stripcslashes($m[1]);
        }
        if (preg_match("/'HTTP_HOST'\\s*=>\\s*'((?:\\\\'|[^'])*)'/", $body, $m)) {
            $entry['httpHost'] = stripcslashes($m[1]);
        }
        if (preg_match("/'HTTP_REFERER'\\s*=>\\s*'((?:\\\\'|[^'])*)'/", $body, $m)) {
            $entry['referer'] = stripcslashes($m[1]);
        }
        if (preg_match("/'__id'\\s*=>\\s*(\\d+)/", $body, $m)) {
            $entry['sessionUserId'] = $m[1];
        }

        $entry['get'] = $this->extractPhpArrayBlock($body, '$_GET');
        $entry['post'] = $this->extractPhpArrayBlock($body, '$_POST');
        $entry['files'] = $this->extractPhpArrayBlock($body, '$_FILES');

        return $entry;
    }

    private function extractPhpArrayBlock(string $body, string $varName): ?string
    {
        $needle = $varName . ' = ';
        $pos = strpos($body, $needle);
        if ($pos === false) {
            return null;
        }

        $i = $pos + strlen($needle);
        $len = strlen($body);
        while ($i < $len && ($body[$i] === ' ' || $body[$i] === "\t")) {
            $i++;
        }
        if ($i >= $len || $body[$i] !== '[') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $start = $i;
        for (; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inString = true;
                continue;
            }
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function passesFilters(
        array $entry,
        string $level,
        string $q,
        string $userId,
        string $category,
        string $uri,
        bool $excludeHttp
    ): bool {
        if ($level !== '' && strcasecmp($entry['level'], $level) !== 0) {
            return false;
        }
        if ($userId !== '') {
            $uid = (string)($entry['userId'] ?? '');
            $sid = (string)($entry['sessionUserId'] ?? '');
            if ($uid !== $userId && $sid !== $userId) {
                return false;
            }
        }
        if ($category !== '' && stripos($entry['category'], $category) === false) {
            return false;
        }
        if ($uri !== '') {
            $hay = (string)($entry['requestUri'] ?? '');
            if ($hay === '' || stripos($hay, $uri) === false) {
                return false;
            }
        }
        if ($excludeHttp && $entry['httpStatus'] !== null
            && in_array((int)$entry['httpStatus'], [400, 401, 403, 404], true)
        ) {
            return false;
        }
        if ($q !== '') {
            $haystack = $entry['raw']
                . ' ' . ($entry['requestUri'] ?? '')
                . ' ' . ($entry['message'] ?? '');
            if (stripos($haystack, $q) === false) {
                return false;
            }
        }

        return true;
    }

    private function shortMessage(string $message): string
    {
        $line = strtok($message, "\n");
        if ($line === false) {
            return '';
        }
        $line = trim($line);
        if (strlen($line) > 220) {
            return substr($line, 0, 217) . '…';
        }

        return $line;
    }

    private function extractExceptionClass(string $message, string $category): ?string
    {
        if (preg_match('/^([A-Za-z0-9_\\\\]+(?:Exception|Error))/', $message, $m)) {
            return $m[1];
        }
        if (preg_match('/\\\\([A-Za-z0-9_]+(?:Exception|Error))/', $category, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractHttpStatus(string $category): ?int
    {
        if (preg_match('/HttpException:(\d{3})/', $category, $m)) {
            return (int)$m[1];
        }

        return null;
    }
}

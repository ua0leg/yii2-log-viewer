<?php

namespace Ua0leg\Yii2LogViewer;

use Yii;

/**
 * Named log file (+ optional rotated history stamp).
 *
 * History files match: {basename}.{stamp}  e.g. app.log.20240921 or app.log.1
 */
class LogFile
{
    public string $name;
    public string $alias;
    public ?string $stamp;

    public function __construct(string $name, string $alias, ?string $stamp = null)
    {
        $this->name = $name;
        $this->alias = $alias;
        $this->stamp = $stamp;
    }

    public static function slug(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
        return trim((string)$slug, '-') ?: 'log';
    }

    public function getSlug(): string
    {
        return self::slug($this->name);
    }

    public function getPath(): string
    {
        $resolved = Yii::getAlias($this->alias);
        if ($this->stamp === null || $this->stamp === '') {
            return $resolved;
        }
        return $resolved . '.' . $this->stamp;
    }

    public function exists(): bool
    {
        return is_file($this->getPath());
    }

    public function isReadable(): bool
    {
        return $this->exists() && is_readable($this->getPath());
    }

    public function getSize(): int
    {
        return $this->exists() ? (int)filesize($this->getPath()) : 0;
    }

    public function getMtime(): int|false
    {
        return $this->exists() ? filemtime($this->getPath()) : false;
    }

    /**
     * Rotated / dated siblings for this alias.
     * @return list<self>
     */
    public function history(): array
    {
        $base = Yii::getAlias($this->alias);
        $items = [];
        foreach (glob($base . '.*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $stamp = substr($file, strlen($base) + 1);
            if ($stamp === '' || str_contains($stamp, '/')) {
                continue;
            }
            $items[] = new self($this->name, $this->alias, $stamp);
        }
        usort($items, static function (self $a, self $b) {
            return ($b->getMtime() ?: 0) <=> ($a->getMtime() ?: 0);
        });
        return $items;
    }

    public function clear(): bool
    {
        if (!$this->exists()) {
            return false;
        }
        return file_put_contents($this->getPath(), '') !== false;
    }
}

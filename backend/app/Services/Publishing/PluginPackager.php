<?php

namespace App\Services\Publishing;

use RuntimeException;
use ZipArchive;

/**
 * Builds the Findriva Connect plugin zip from resources/wordpress, so the download always matches
 * the reviewed source in the repository. The zip is cached in storage and rebuilt when the source changes.
 */
class PluginPackager
{
    public const SLUG = 'findriva-connect';

    public function sourceDir(): string
    {
        return resource_path('wordpress/'.self::SLUG);
    }

    /** @return array<string, string> relative path => absolute path, sorted */
    public function files(): array
    {
        $dir = $this->sourceDir();
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/')] = $file->getPathname();
            }
        }
        ksort($out);

        return $out;
    }

    public function fingerprint(): string
    {
        $h = hash_init('sha256');
        foreach ($this->files() as $rel => $abs) {
            hash_update($h, $rel."\0".hash_file('sha256', $abs)."\0");
        }

        return substr(hash_final($h), 0, 16);
    }

    /** Path to an up-to-date zip with a single top-level findriva-connect/ folder, as WordPress expects. */
    public function zip(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is needed to package the WordPress plugin.');
        }
        $dir = storage_path('app/plugins');
        $path = $dir.'/'.self::SLUG.'-'.$this->fingerprint().'.zip';
        if (is_file($path)) {
            return $path;
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the plugin zip.');
        }
        $zip->addEmptyDir(self::SLUG);
        foreach ($this->files() as $rel => $abs) {
            $zip->addFile($abs, self::SLUG.'/'.$rel);
        }
        $zip->close();
        rename($tmp, $path);
        foreach (glob($dir.'/'.self::SLUG.'-*.zip') ?: [] as $old) {
            if ($old !== $path) {
                @unlink($old);
            }
        }

        return $path;
    }
}

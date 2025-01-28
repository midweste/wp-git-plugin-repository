<?php

namespace Midweste\GitPluginRepository;

class Helpers
{
    public static function date_to_version(string $date): string
    {
        return str_replace(['-', 'T', ':', '+'], '.', rtrim($date, 'Z'));
    }

    protected static function rmdir(string $dir): bool
    {
        if (!is_dir($dir) || strpos($dir, self::cache_dir()) !== 0) {
            return false;
        }
        WP_Filesystem();
        global $wp_filesystem;

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                $wp_filesystem->rmdir($file->getRealPath());
            } else {
                $wp_filesystem->delete($file->getRealPath());
            }
        }
        return $wp_filesystem->rmdir($dir);
    }

    public static function cache_dir(): string
    {
        $upload_dir = wp_upload_dir();
        $cache_path = $upload_dir['basedir'] . '/cache/plugins';
        return $cache_path;
    }

    public static function cache_url(): string
    {
        $upload_dir = wp_upload_dir();
        $cache_url = $upload_dir['baseurl'] . '/cache/plugins';
        return $cache_url;
    }

    public static function zip(string $file_name, string $path, string $new_path): bool
    {
        if (empty($path) || empty($file_name) || (!is_file($path) && !is_dir($path))) {
            return false;
        }
        mbstring_binary_safe_encoding();
        require_once \ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $archive = new \PclZip($file_name);
        $zipfiles = [
            [
                \PCLZIP_ATT_FILE_NAME => $path,
                \PCLZIP_ATT_FILE_NEW_FULL_NAME => $new_path,
            ],
        ];
        if ($archive->create($zipfiles) === 0) {
            return false;
        }
        reset_mbstring_encoding();
        return true;
    }

    // public static function unzip(string $file_name, string $path, string $new_path): bool
    // {
    //     if (empty($path) || empty($file_name) || (!is_file($path) && !is_dir($path))) {
    //         return false;
    //     }
    //     mbstring_binary_safe_encoding();
    //     require_once \ABSPATH . 'wp-admin/includes/class-pclzip.php';
    //     $archive = new \PclZip($file_name);
    //     if ($archive->extract() === 0) {
    //         return false;
    //     }
    //     reset_mbstring_encoding();
    //     return true;
    // }

    public static function dir_child(string $path): string
    {
        $subfolder = glob($path . '/*', \GLOB_ONLYDIR);
        if (!$subfolder) {
            return '';
        }
        $first_subfolder = current($subfolder);
        return $first_subfolder;
    }

    // public static function plugin_file_from_slug(string $slug)
    // {
    //     $basename = plugin_basename($slug);
    //     return $basename . \DIRECTORY_SEPARATOR . $basename . '.php';
    // }

    public static function slug_from_plugin_file(string $plugin_file): string
    {
        return current(explode('/', $plugin_file));
    }

    public static function url_to_path(string $url): string
    {
        $path = str_replace(site_url() . '/', ABSPATH, $url);
        return $path;
    }

    public static function copy_recursive($source, $destination, $filter = null)
    {
        WP_Filesystem();
        global $wp_filesystem;

        if (!is_dir($destination)) {
            $wp_filesystem->mkdir($destination);
        }

        $dir = new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS);

        $filter = (is_callable($filter)) ? $filter : function ($current, $key, $iterator) {
            return true;
        };

        $files = new \RecursiveCallbackFilterIterator($dir, $filter);
        $iterator = new \RecursiveIteratorIterator($files, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $item_destination = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($item->isDir()) {
                $result = $wp_filesystem->mkdir($item_destination);
            } else {
                $result = $wp_filesystem->copy($item->getRealPath(), $item_destination, true);
            }

            if ($result === false) {
                return false;
            }
        }
        return true;
    }

    public static function mu_update(string $plugin_file): bool
    {
        try {
            $updater = UpdaterBase::getUpdater($plugin_file);
            if (!$updater) {
                return false;
            }

            $plugin_data = $updater->getPluginData();
            if (!isset($plugin_data['UpdateURI'])) {
                return false;
            }

            $update_data = $updater->update();
            if (empty($update_data->package)) {
                return false;
            }

            $package_dir = self::url_to_path($update_data->package);
            if (filesize($package_dir) === 0) {
                return false;
            }

            WP_Filesystem();
            /** @var WP_Filesystem_Direct $wp_filesystem */
            global $wp_filesystem;

            // Replace the version in the file
            $tmp_dir = self::cache_dir() . \DIRECTORY_SEPARATOR . $update_data->slug;
            $wp_filesystem->rmdir($tmp_dir, true);
            $unzipped = unzip_file($package_dir, $tmp_dir);
            if (is_wp_error($unzipped)) {
                return false;
            }
            $new_plugin_root = self::dir_child($tmp_dir);
            $versioned = self::replace_version_in_file($new_plugin_root . \DIRECTORY_SEPARATOR . $update_data->slug, $update_data->new_version);
            if (!$versioned) {
                return false;
            }

            // Backup mu directory
            $time = time();
            $mu_backup_dir = sprintf(WP_CONTENT_DIR . '/mu-plugins.bak', $time);
            $wp_filesystem->rmdir($mu_backup_dir, true);
            if (!self::copy_recursive(WPMU_PLUGIN_DIR, $mu_backup_dir)) {
                return false;
            }

            // copy new files, restore backup if failed
            try {
                $copy_result = self::copy_recursive($new_plugin_root, WPMU_PLUGIN_DIR, function ($current, $key, $iterator) {
                    if ($iterator->hasChildren()) {
                        return true;
                    }
                    if (pathinfo($current->getFilename(), PATHINFO_EXTENSION) !== '') {
                        return true;
                    }
                    return false;
                });
                if (!$copy_result) {
                    throw new \Exception('Could not copy files.');
                }
            } catch (\Throwable $e) {
                $wp_filesystem->move($mu_backup_dir, WPMU_PLUGIN_DIR, true);
                throw new \Exception(sprintf('Could not copy files. %s', $e->getMessage()));
            } finally {
                $wp_filesystem->rmdir($mu_backup_dir, true);
                $wp_filesystem->rmdir($tmp_dir, true);
            }
        } catch (\Throwable $e) {
            throw new \Exception(sprintf('Could not update. %s', $e->getMessage()));
        }
        return true;
    }

    public static function replace_version_in_file(string $file, string $new_version): bool
    {
        if (!is_file($file)) {
            return false;
        }

        WP_Filesystem();
        global $wp_filesystem;
        $contents = $wp_filesystem->get_contents($file);

        $version_pattern = '/^(\s*\**\s*Version\s*:\s*)(.*)$/im';
        $name_pattern = '/^(\s*\**\s*Plugin\s*Name\s*:\s*)(.*)$/im';
        $has_version = (preg_match($version_pattern, $contents, $matches)) ? true : false;

        if ($has_version) {
            // replace the version string in plugin file with new version
            $contents = preg_replace($version_pattern, '${1}' . $new_version, $contents);
        } else {
            // add version string to plugin file after Name:
            $contents = preg_replace($name_pattern, '${1}${2}' . "\n * Version: " . $new_version, $contents);
        }
        return $wp_filesystem->put_contents($file, $contents);
    }

    public static function zip_proxy(string $url, string $plugin_file, string $new_version): string
    {
        $cache_path = self::cache_dir();
        $cache_url = self::cache_url();

        $slug = self::slug_from_plugin_file($plugin_file);
        $zipfolder_path = $cache_path . \DIRECTORY_SEPARATOR . $slug;
        $zipfile_path = $cache_path . \DIRECTORY_SEPARATOR . $slug . '-' . $new_version . '.zip';
        $zipfile_url = $cache_url . '/' . $slug . '-' . $new_version . '.zip';

        if (is_file($zipfile_path)) {
            return $zipfile_url;
        }

        try {
            // create cache folder if it doesn't exist
            if (!is_dir($cache_path)) {
                wp_mkdir_p($cache_path);
            }

            // download file
            $tmp_file = download_url($url);

            self::rmdir($zipfolder_path);
            unzip_file($tmp_file, $zipfolder_path);

            // remove first subfolder as git downloads them with a random hash in the folder name
            $subfolder = glob($zipfolder_path . '/*', \GLOB_ONLYDIR);
            if (!$subfolder) {
                return '';
            }
            $first_subfolder = current($subfolder);

            // replace version number in newly downloaded plugin before zipping
            $new_plugin_file_path = $first_subfolder . \DIRECTORY_SEPARATOR . $slug . '.php';
            // $current_plugin_data = self::get_plugin_data($plugin_file);
            self::replace_version_in_file($new_plugin_file_path, $new_version);

            // wrap it up
            self::zip($zipfile_path, $first_subfolder, $slug);
            self::rmdir($zipfolder_path);

            return $zipfile_url;
        } catch (\Throwable $e) {
            throw new \Exception(sprintf('Could not proxy remote zipfile. %s', $e->getMessage()));
        }
        return '';
    }
}

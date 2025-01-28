<?php

namespace Midweste\GitPluginRepository;

abstract class UpdaterBase
{
    protected array $plugin_data;
    protected string $plugin_file;
    protected $request_cache = [];

    abstract public static function getUpdateHost(): string;
    abstract public function getLatestVersion(): string;
    abstract public function getLatestVersionZip(): string;

    public function __construct(string $plugin_file)
    {
        if (empty($plugin_file)) {
            throw new \Exception(sprintf('Missing plugin file.'));
        }

        $plugin_data = self::getPlugin($plugin_file);
        if (empty($plugin_data)) {
            throw new \Exception(sprintf('Could not get plugin data for "%s".', $plugin_file));
        }

        if (empty(trim($plugin_data['UpdateURI'])) || !filter_var($plugin_data['UpdateURI'], FILTER_VALIDATE_URL)) {
            throw new \Exception(sprintf('Missing or invalid Update URI.'));
        }

        $this->plugin_data = $plugin_data;
        $this->plugin_file = $plugin_file;
    }


    public function getPluginData(string $key = '')
    {
        if (empty($key)) {
            return $this->plugin_data;
        }
        return (isset($this->plugin_data[$key])) ? $this->plugin_data[$key] : [];
    }

    public function getPluginFile(): string
    {
        return $this->plugin_file;
    }

    protected function getRequestCache(): array
    {
        return $this->request_cache;
    }

    public function getSlug(): string
    {
        return current(explode('/', $this->getPluginFile()));
    }

    public function update($locales = []): object
    {
        $version = !empty($this->getPluginData('Version')) ? $this->getPluginData('Version') : 0;
        $new_version = ltrim($this->getLatestVersion(), 'v');

        $update_object = (object) [
            'slug' => $this->getSlug(),  // needed for /wp-admin/update-core.php
            'version' => $version,
            'new_version' => $new_version,
            'package' => null,
        ];

        if (empty($new_version) || !version_compare($new_version, $version, '>')) {
            return $update_object;
        }

        $package_url = $this->getLatestVersionZip();
        if (!filter_var($package_url, FILTER_VALIDATE_URL) || !$this->zipVerify($package_url)) {
            return $update_object;
        }

        $package_local = Helpers::zip_proxy($package_url, $this->getPluginFile(), $new_version);
        if (empty($package_local)) {
            return $update_object;
        }

        $update_object->package = $package_local;
        return $update_object;
    }

    protected function request(string $url)
    {
        if (isset($this->getRequestCache()[$url])) {
            return $this->getRequestCache()[$url];
        }
        $response = wp_remote_get($url);
        $this->request_cache[$url] = $response;
        return $response;
    }

    protected function requestBody(string $url)
    {
        $request = $this->request($url);
        $data = json_decode(wp_remote_retrieve_body($request), true);
        return (is_array($data)) ? $data : false;
    }

    protected function requestHeaders(string $url): array
    {
        $stream = [
            'http' => [
                'user_agent' => 'Mozilla/5.0',
            ],
        ];
        $response = get_headers($url, true, stream_context_create($stream));
        return $response;
    }

    protected function zipVerify(string $url): bool
    {
        $response = $this->requestHeaders($url);
        if (!isset($response['Content-Type'])) {
            return false;
        }
        if (is_array($response['Content-Type']) && strpos(end($response['Content-Type']), 'application/zip') === 0) {
            return true;
        }
        if (!is_array($response['Content-Type']) && strpos($response['Content-Type'], 'application/zip') === 0) {
            return true;
        }
        return false;
    }

    public static function getPlugin(string $plugin_file): array
    {
        // standard plugin
        if (strpos($plugin_file, \DIRECTORY_SEPARATOR) !== false) {
            $plugin_absfile = WP_PLUGIN_DIR . \DIRECTORY_SEPARATOR . $plugin_file;
            $plugin_data = get_plugin_data($plugin_absfile, false, false);
            $plugin_data['Type'] = 'plugin';
            $plugin_data['Pluginfile'] = $plugin_absfile;
            $plugin_data['Plugindir'] = WP_PLUGIN_DIR;
            return $plugin_data;
        }

        // must be a must-use plugin
        $mu_plugins = get_mu_plugins();
        if (empty($mu_plugins) || empty($mu_plugins[$plugin_file])) {
            return [];
        }
        $plugin_data = $mu_plugins[$plugin_file];
        $plugin_data['Type'] = 'muplugin';
        $plugin_data['Pluginfile'] = WPMU_PLUGIN_DIR . \DIRECTORY_SEPARATOR . $plugin_file;
        $plugin_data['Plugindir'] = WPMU_PLUGIN_DIR;
        return $plugin_data;
    }

    public static function getImplementations(): array
    {
        static $implementations = [];
        if (!empty($implementations)) {
            return $implementations;
        }

        $classes = get_declared_classes();
        foreach ($classes as $class) {
            if (is_subclass_of($class, __CLASS__)) {
                $implementations[] = '\\' . $class;
            }
        }
        return $implementations;
    }

    public static function getUpdater(string $plugin_file): ?self
    {
        $plugin_data = self::getPlugin($plugin_file);
        if (empty($plugin_data['UpdateURI'])) {
            return null;
        }
        $host = wp_parse_url($plugin_data['UpdateURI'], PHP_URL_HOST);
        foreach (self::getImplementations() as $implementation) {
            if ($implementation::getUpdateHost() === $host) {
                return new $implementation($plugin_file);
            }
        }
        return null;
    }
}

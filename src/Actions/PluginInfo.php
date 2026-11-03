<?php



namespace FC\Actions;

use FC\FileSystem;
use WP_REST_Request;
use WP_REST_Response;

class PluginInfo extends AbstractAction
{
  public function getIcon(): string
  {
    return '';
  }

  public function getName(): string
  {
    return 'Obter informações do plugin';
  }

  public function getShortDescription(): string
  {
    return 'Busca informações do repositório da FULL sobre um plugin específico.';
  }

  public function getPromptArgs(): array
  {
    return array_merge($this->_defaultPromptArgs(), []);
  }

  public function getRestMethod(): string
  {
    return 'GET';
  }

  public function getRestRoute(): string
  {
    return 'actions/plugins/info/(?P<pluginSlug>[a-zA-Z0-9-_]+)';
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    $slug = preg_replace('/[^a-zA-Z0-9-_]/', '', sanitize_text_field($request->get_param('pluginSlug')));

    if (empty($slug)) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Plugin não especificado.'
      ], 400);
    }

    $data = fcDashboardAPI('GET', 'plugin-repository/' . $slug . '/info');
    $plugin = $data['success'] ? $data['data'] : null;

    if (!$plugin) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Plugin não localizado no repositório da FULL.'
      ], 404);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $localPluginPath = trailingslashit(WP_PLUGIN_DIR) . $plugin['plugin'];
    $isInstalled     = file_exists($localPluginPath);
    $isActive        = $isInstalled ? is_plugin_active($plugin['plugin']) : false;
    $localVersion    = '';
    $upToDate        = false;
    $bundledRelease  = false;

    if ($isInstalled) {
      $localPluginData = get_plugin_data($localPluginPath, false, true);
      $localVersion    = $localPluginData['Version'];
      $upToDate        = version_compare($localVersion, $plugin['version'], '>=');
    }

    if (!$upToDate) {
      $fs = FileSystem::instance();
      $bundlePath = $fs->getBundledReleasePath((string) $plugin['slug']);

      if ($fs->isFile($bundlePath)) {
        $bundledRelease = true;
      }
    }

    return new WP_REST_Response([
      'success' => true,
      'data' => [
        'slug' => $plugin['slug'],
        'version' => $plugin['version'],
        'package' => $plugin['package'], // URL do ZIP
        'plugin' => $plugin['plugin'],   // arquivo principal (slug/slug.php)
        'dependencies' => $plugin['dependencies'] ?? [],
        'local' => [
          'installed' => $isInstalled,
          'active' => $isActive,
          'version' => $localVersion,
          'upToDate' => $upToDate,
          'bundled' => $bundledRelease,
        ]
      ]
    ]);
  }
}

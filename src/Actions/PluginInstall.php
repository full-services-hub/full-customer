<?php

namespace FC\Actions;

use FC\FileSystem;
use WP_REST_Request;
use WP_REST_Response;

class PluginInstall extends AbstractAction
{
  public function getIcon(): string
  {
    return '';
  }

  public function getName(): string
  {
    return 'Instalar plugin';
  }

  public function getShortDescription(): string
  {
    return 'Permite o usuário instalar os plugins que ele tem pela FULL.';
  }

  public function getPromptArgs(): array
  {
    return array_merge($this->_defaultPromptArgs(), []);
  }

  public function getRestMethod(): string
  {
    return 'POST';
  }

  public function getRestRoute(): string
  {
    return 'actions/plugins/install/(?P<processId>[a-zA-Z0-9-]+)';
  }

  public function showInActionsDropdown(): bool
  {
    return false;
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $fs = FileSystem::instance();

    $pid = preg_replace('/[^a-zA-Z0-9-]/', '', sanitize_text_field($request->get_param('processId')));
    $slug = preg_replace('/[^a-zA-Z0-9-_]/', '', sanitize_text_field($request->get_param('pluginSlug')));

    $data = fcDashboardAPI('GET', 'plugin-repository/' . $slug . '/info', ['activation' => 1]);
    $plugin = $data['success'] ? $data['data'] : [];

    if (!$plugin) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Plugin não localizado no repositório da FULL. para instalação'
      ]);
    }

    ExecutionStatus::updateState($pid, 'Verificando dependências...');

    if (!empty($plugin['dependencies'])) {
      $error = $this->resolvePluginDependencies($plugin, $pid);

      if (!is_null($error)) {
        return $error;
      }
    }

    ExecutionStatus::updateState($pid, 'Dependências verificadas, iniciando processo de instalação principal...');

    $localPluginPath = trailingslashit(WP_PLUGIN_DIR) . $plugin['plugin'];

    if ($fs->isFile($localPluginPath)) {
      $localPluginData = get_plugin_data($localPluginPath, false, true);

      if (version_compare($localPluginData['Version'], $plugin['version'], '>=')) {
        return new WP_REST_Response([
          'success' => true,
          'message' => 'Plugin já instalado na versão mais recente, podemos continuar rapidamente'
        ]);
      }
    }

    $zipPath = $fs->wpContentDir() . 'upgrade/' . $plugin['slug'] . '-' . $pid . '.zip';
    if (!$fs->isFile($zipPath)) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Arquivo de instalação do plugin não localizado no servidor. Por favor, tente novamente.'
      ], 404);
    }

    $workingDir = $fs->wpContentDir() . 'upgrade/' . $plugin['slug'];
    $recoveryLink = ' <a href="' . $plugin['package'] . '">Baixar plugin manualmente</a> ';

    if ($fs->isDir($workingDir)) {
      $fs->delete($workingDir, true);
    }

    $fs->mkdir($workingDir);
    $unzipped = unzip_file($zipPath, $workingDir);

    if (is_wp_error($unzipped)) {
      $fs->delete($zipPath);
      $fs->delete($workingDir, true);
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Houve um erro ao descompactar o arquivo do plugin. ' . $unzipped->get_error_message() . ' ' . $recoveryLink
      ]);
    }

    ExecutionStatus::updateState($pid, 'Arquivo descompactado');

    $fs->delete($zipPath);

    $copied = copy_dir($workingDir, WP_PLUGIN_DIR);
    $fs->delete($workingDir, true);

    if (is_wp_error($copied)) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Houve um erro ao copiar o arquivo do plugin. ' . $copied->get_error_message() . ' ' . $recoveryLink
      ]);
    }

    ExecutionStatus::updateState($pid, 'Arquivo transferido.');

    return new WP_REST_Response([
      'success' => true,
      'message' => 'Plugin instalado com sucesso no seu WordPress.'
    ]);
  }

  private function resolvePluginDependencies(array $plugin, string $pid): ?WP_REST_Response
  {
    foreach ($plugin['dependencies'] as $dep) {
      ExecutionStatus::updateState($pid, 'Verificando dependência ' . $dep . '...');

      wp_clean_plugins_cache();
      $all_plugins = get_plugins();
      $dependency_file = '';

      foreach (array_keys($all_plugins) as $file) {
        if (dirname($file) === $dep || $file === $dep . '.php') {
          $dependency_file = $file;
          break;
        }
      }

      if (!empty($dependency_file) && is_plugin_active($dependency_file)) {
        ExecutionStatus::updateState($pid, 'Dependência ' . $dep . ' já instalada e ativa.');
        continue;
      }

      if (!empty($dependency_file) && !is_plugin_active($dependency_file)) {
        ExecutionStatus::updateState($pid, 'Dependência ' . $dep . ' encontrada no disco. Ativando...');

        $activated = activate_plugin($dependency_file);

        if (is_wp_error($activated)) {
          return new WP_REST_Response([
            'success' => false,
            'error' => 'Erro fatal ao ativar a dependência existente: ' . $dep . '. ' . $activated->get_error_message()
          ]);
        }
        continue;
      }

      ExecutionStatus::updateState($pid, 'Baixando e instalando dependência ' . $dep . '...');

      $request = new WP_REST_Request('POST', '/wp/v2/plugins');
      $request->set_param('slug', $dep);
      $request->set_param('status', 'active');
      $request->set_param('context', 'edit');

      rest_do_request($request);

      wp_clean_plugins_cache();
      $all_plugins = get_plugins();
      $dependency_file = '';

      foreach (array_keys($all_plugins) as $file) {
        if (dirname($file) === $dep || $file === $dep . '.php') {
          $dependency_file = $file;
          break;
        }
      }

      if (!empty($dependency_file) && !is_plugin_active($dependency_file)) {
        ExecutionStatus::updateState($pid, 'Forçando ativação da dependência recém-instalada ' . $dep . '...');

        $activated = activate_plugin($dependency_file);

        if (is_wp_error($activated)) {
          return new WP_REST_Response([
            'success' => false,
            'error' => 'Erro fatal ao ativar a dependência após instalação: ' . $dep . '. ' . $activated->get_error_message()
          ]);
        }
      }
    }

    return null;
  }
}

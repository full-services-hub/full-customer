<?php



namespace FC\Services;

use FC\Actions\PluginInstall;
use FC\Actions\PluginLicense;
use FC\Actions\PluginWordPressActivate;
use FC\FileSystem;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class RemoteActivation
{
  public function __construct()
  {
    fcRegisterRestRoute(
      'POST',
      'remote-activation',
      [$this, 'remoteActivate'],
      [$this, 'validateSignatureCallback']
    );

    add_action('fc_remote_activate_step', [$this, 'executeStep']);
    add_action('init', [$this, 'initQueueHook']);
  }
  public function initQueueHook(): void
  {
    $queue = get_option('fc/remote-activation-queue');
    if (is_array($queue) && !empty($queue)) {
      add_action('shutdown', [$this, 'processQueueOnShutdown']);
    }
  }

  public function processQueueOnShutdown(): void
  {
    if (get_transient('fc/remote-activation-lock')) {
      return;
    }
    set_transient('fc/remote-activation-lock', true, 30);

    $queue = get_option('fc/remote-activation-queue');
    if (!is_array($queue) || empty($queue)) {
      delete_option('fc/remote-activation-queue');
      delete_transient('fc/remote-activation-lock');
      return;
    }

    $updatedQueue = [];
    $processedOne = false;

    foreach ($queue as $pid) {
      $state = get_transient('fc/remote-activation/' . $pid);

      if (!is_array($state) || in_array($state['status'] ?? '', ['completed', 'failed'], true)) {
        continue;
      }

      if (!$processedOne) {
        $processedOne = true;
        $this->executeStep($pid);

        $newState = get_transient('fc/remote-activation/' . $pid);
        if (is_array($newState) && !in_array($newState['status'] ?? '', ['completed', 'failed'], true)) {
          $updatedQueue[] = $pid;
        }
      } else {
        $updatedQueue[] = $pid;
      }
    }

    if (empty($updatedQueue)) {
      delete_option('fc/remote-activation-queue');
    } else {
      update_option('fc/remote-activation-queue', $updatedQueue, false);
    }

    delete_transient('fc/remote-activation-lock');
  }

  public function validateSignatureCallback(WP_REST_Request $request)
  {
    $signature = $request->get_header('signature')
      ?: $request->get_header('x-full-signature')
      ?: $request->get_param('signature');

    if (empty($signature)) {
      return new WP_Error(
        'rest_forbidden',
        'Assinatura não fornecida no cabeçalho.',
        ['status' => 400]
      );
    }

    if (!$this->isValidSignature($signature)) {
      return new WP_Error(
        'rest_forbidden',
        'Assinatura inválida, recusada pelo painel ou expirada.',
        ['status' => 403]
      );
    }

    return true;
  }

  private function isValidSignature(string $signature): bool
  {
    $response = fcDashboardAPI('POST', 'staff/validate-activation-starter', ['signature' => $signature]);
    return $response['success'] && $response['status'] === 200;
  }

  public function remoteActivate(WP_REST_Request $request): WP_REST_Response
  {
    $params = $request->get_json_params();
    $slug = preg_replace('/[^a-zA-Z0-9-_]/', '', sanitize_text_field($params['pluginSlug'] ?? ''));

    if (empty($slug)) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Identificador de plugin (pluginSlug) inválido ou ausente.'
      ], 400);
    }

    $pluginPath = sanitize_text_field($params['plugin'] ?? '');
    $pid = uniqid('remote-', true);
    $pid = preg_replace('/[^a-zA-Z0-9-]/', '', $pid);

    $state = [
      'processId' => $pid,
      'pluginSlug' => $slug,
      'plugin' => $pluginPath,
      'status' => 'pending',
      'currentStep' => 'install',
      'licenseStep' => '',
      'licenseState' => [],
      'attempts' => 0,
    ];

    set_transient('fc/remote-activation/' . $pid, $state, DAY_IN_SECONDS);

    $queue = get_option('fc/remote-activation-queue');
    $queue = is_array($queue) ? $queue : [];
    $queue[] = $pid;
    update_option('fc/remote-activation-queue', $queue, false);

    wp_schedule_single_event(time() + 60, 'fc_remote_activate_step', [$pid], true);

    return new WP_REST_Response([
      'success' => true,
      'message' => 'Ativação remota agendada com sucesso. O processo iniciará em 1 minuto.',
      'processId' => $pid,
    ]);
  }

  public function executeStep(string $pid): void
  {
    $state = get_transient('fc/remote-activation/' . $pid);

    if (!is_array($state)) {
      return;
    }

    if (in_array($state['status'], ['completed', 'failed'])) {
      return;
    }

    $state['status'] = 'running';
    $state['attempts'] = (isset($state['attempts']) ? (int) $state['attempts'] : 0) + 1;

    if ($state['attempts'] > 3) {
      $this->fail($pid, $state, 'Excedeu o limite máximo de tentativas de execução em background.');
      return;
    }

    set_transient('fc/remote-activation/' . $pid, $state, DAY_IN_SECONDS);

    $currentStep = $state['currentStep'];

    try {
      if ($currentStep === 'install') {
        $this->stepInstall($pid, $state);
      } elseif ($currentStep === 'wp_activate') {
        $this->stepWpActivate($pid, $state);
      } elseif ($currentStep === 'license') {
        $this->stepLicense($pid, $state);
      } else {
        $this->fail($pid, $state, 'Etapa de ativação desconhecida: ' . $currentStep);
      }
    } catch (\Throwable $e) {
      $this->fail($pid, $state, 'Erro inesperado na execução: ' . $e->getMessage());
    }
  }

  private function stepInstall(string $pid, array $state): void
  {
    $slug = $state['pluginSlug'];
    $info = fcDashboardAPI('GET', 'plugin-repository/' . $slug . '/info');

    if (!$info['success'] || empty($info['data'])) {
      $this->fail($pid, $state, 'Não foi possível recuperar as informações do plugin no repositório: ' . ($info['message'] ?? ''));
      return;
    }

    $pluginData = $info['data'];

    if (empty($state['plugin'])) {
      $state['plugin'] = $pluginData['plugin'];
    }

    $fs = FileSystem::instance();
    $localPluginPath = trailingslashit(WP_PLUGIN_DIR) . $pluginData['plugin'];

    $alreadyInstalled = false;
    if ($fs->isFile($localPluginPath)) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
      $localPluginData = get_plugin_data($localPluginPath, false, true);

      if (version_compare($localPluginData['Version'], $pluginData['version'], '>=')) {
        $alreadyInstalled = true;
      }
    }

    if (!$alreadyInstalled) {
      $zipPath = $fs->wpContentDir() . 'upgrade/' . $pluginData['slug'] . '-' . $pid . '.zip';

      if (!$fs->isFile($zipPath)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tempFile = download_url($pluginData['package']);

        if (is_wp_error($tempFile)) {
          $this->fail($pid, $state, 'Erro ao baixar pacote do plugin: ' . $tempFile->get_error_message());
          return;
        }

        $upgradeDir = dirname($zipPath);
        if (!$fs->isDir($upgradeDir)) {
          $fs->mkdir($upgradeDir);
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
          require_once ABSPATH . 'wp-admin/includes/file.php';
          WP_Filesystem();
        }

        if (!$wp_filesystem->move($tempFile, $zipPath, true)) {
          $wp_filesystem->delete($tempFile);
          $this->fail($pid, $state, 'Falha ao mover arquivo temporário de instalação.');
          return;
        }
      }

      $installAction = new PluginInstall();
      $request = new WP_REST_Request('POST', '');
      $request->set_param('processId', $pid);
      $request->set_param('pluginSlug', $slug);

      $response = $installAction->restHandler($request);
      $resData = $response->get_data();

      if (empty($resData['success'])) {
        $this->fail($pid, $state, 'Falha ao instalar o plugin: ' . ($resData['error'] ?? 'Erro desconhecido.'));
        return;
      }
    }

    $state['currentStep'] = 'wp_activate';
    $state['status'] = 'pending';
    set_transient('fc/remote-activation/' . $pid, $state, DAY_IN_SECONDS);

    wp_clear_scheduled_hook('fc_remote_activate_step', [$pid]);
    wp_schedule_single_event(time(), 'fc_remote_activate_step', [$pid]);
    wp_cron();
  }

  private function stepWpActivate(string $pid, array $state): void
  {
    $slug = $state['pluginSlug'];
    $pluginPath = $state['plugin'];

    if (empty($pluginPath)) {
      $info = fcDashboardAPI('GET', 'plugin-repository/' . $slug . '/info');
      if ($info['success'] && !empty($info['data']['plugin'])) {
        $pluginPath = $info['data']['plugin'];
        $state['plugin'] = $pluginPath;
      } else {
        $this->fail($pid, $state, 'O arquivo de inicialização do plugin não pôde ser determinado.');
        return;
      }
    }

    $activateAction = new PluginWordPressActivate();
    $request = new WP_REST_Request('POST', '');
    $request->set_param('processId', $pid);
    $request->set_param('plugin', $pluginPath);

    $response = $activateAction->restHandler($request);
    $resData = $response->get_data();

    if (empty($resData['success'])) {
      $this->fail($pid, $state, 'Falha ao ativar o plugin no WordPress: ' . ($resData['error'] ?? 'Erro desconhecido.'));
      return;
    }

    $state['currentStep'] = 'license';
    $state['status'] = 'pending';
    set_transient('fc/remote-activation/' . $pid, $state, DAY_IN_SECONDS);

    wp_clear_scheduled_hook('fc_remote_activate_step', [$pid]);
    wp_schedule_single_event(time(), 'fc_remote_activate_step', [$pid]);
    wp_cron();
  }

  private function stepLicense(string $pid, array $state): void
  {
    $slug = $state['pluginSlug'];

    $licenseAction = new PluginLicense();
    $request = new WP_REST_Request('POST', '');
    $request->set_param('processId', $pid);
    $request->set_param('pluginSlug', $slug);
    $request->set_param('step', $state['licenseStep'] ?? '');
    $request->set_param('state', $state['licenseState'] ?? []);

    $response = $licenseAction->restHandler($request);
    $resData = $response->get_data();

    if (empty($resData['success'])) {
      $this->fail($pid, $state, 'Falha na validação/processamento da licença: ' . ($resData['error'] ?? 'Erro desconhecido.'));
      return;
    }

    $result = $resData['result'] ?? [];
    $isCompleted = !isset($result['completed']) || $result['completed'] === true;

    if ($isCompleted) {
      $state['status'] = 'completed';
      set_transient('fc/remote-activation/' . $pid, $state, DAY_IN_SECONDS);

      $queue = get_option('fc/remote-activation-queue');
      if (is_array($queue)) {
        $queue = array_diff($queue, [$pid]);
        if (empty($queue)) {
          delete_option('fc/remote-activation-queue');
        } else {
          update_option('fc/remote-activation-queue', $queue, false);
        }
      }
    } else {
      $state['licenseStep'] = $result['step'] ?? '';
      $state['licenseState'] = $result['state'] ?? [];
      $state['status'] = 'pending';
      set_transient('fc/remote-activation/' . $pid, $state, DAY_IN_SECONDS);

      wp_clear_scheduled_hook('fc_remote_activate_step', [$pid]);
      wp_schedule_single_event(time(), 'fc_remote_activate_step', [$pid]);
      wp_cron();
    }
  }

  private function fail(string $pid, array $state, string $message): void
  {
    $slug = $state['pluginSlug'];
    $state['status'] = 'failed';
    $state['error'] = $message;
    set_transient('fc/remote-activation/' . $pid, $state, DAY_IN_SECONDS);

    $queue = get_option('fc/remote-activation-queue');
    if (is_array($queue)) {
      $queue = array_diff($queue, [$pid]);
      if (empty($queue)) {
        delete_option('fc/remote-activation-queue');
      } else {
        update_option('fc/remote-activation-queue', $queue, false);
      }
    }

    fcDashboardAPI('POST', 'analytics/fc-error', [
      'error' => [
        'message' => 'Remote activation failed for plugin ' . $slug . ': ' . $message,
        'file' => __FILE__,
        'line' => __LINE__,
        'date' => current_time('mysql')
      ]
    ]);
  }
}

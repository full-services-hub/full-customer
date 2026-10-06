<?php

namespace FC\Services;

use FC\FileSystem;
use FC\ModelRepository;
use WP_REST_Request;
use WP_REST_Response;

class Rest
{
  public function __construct()
  {
    add_action('init', [$this, 'actions']);

    fcRegisterRestRoute('GET', 'skills', [$this, 'skills']);

    fcRegisterRestRoute('POST', 'local-license-processor', [$this, 'localLicenseProcessor'], '__return_true');

    fcRegisterRestRoute('GET', 'beacon', [$this, 'beacon'], '__return_true');
  }

  public function actions(): void
  {
    $processed = [];
    $actions = [
      new \FC\Actions\ConnectAccount(),
      new \FC\Actions\ViewConnectedAccount(),
      new \FC\Actions\DisconnectAccount(),
      new \FC\Actions\PluginLicenseExtract(),
      new \FC\Actions\PluginRepository(),
      new \FC\Actions\CleanUpCache(),
      new \FC\Actions\Backlink(),
      new \FC\Actions\PluginInstall(),
      new \FC\Actions\PluginLicense(),
      new \FC\Actions\PluginWordPressActivate(),
      new \FC\Actions\PluginFullActivate(),
      new \FC\Actions\ExecutionStatus(),
      new \FC\Actions\PluginActivationManager(),
      new \FC\Actions\PluginReactivate(),
      new \FC\Actions\PluginInfo(),
      new \FC\Actions\PluginUploadChunk(),
      new \FC\Actions\PluginDownload(),
    ];

    foreach ($actions as $action) {
      $this->register_action_recursive($action, $processed);
    }
  }

  private function register_action_recursive($action, array &$processed)
  {
    $class = is_string($action) ? $action : get_class($action);

    if (in_array($action, $processed))
      return;

    $instance = is_string($action) ? new $action() : $action;

    if ($instance->isShell()) {
      foreach ($instance->inShellActions() as $subActionClass) {
        $this->register_action_recursive($subActionClass, $processed);
      }
    } else {
      fcRegisterRestRoute(
        $instance->getRestMethod(),
        $instance->getRestRoute(),
        [$instance, 'restHandler']
      );
    }

    $processed[] = $class;
  }

  public function skills(): WP_REST_Response
  {
    $list = [];

    foreach (ModelRepository::instance()->all() as $model) {
      $agents = [];

      foreach ($model->agents() as $agent) {
        $agentActions = [];

        foreach ($agent->actions() as $action) {
          if (!$action->isAvailable())
            continue;
          $agentActions[] = $action->getPromptArgs();
        }

        $agents[] = [
          'id' => $agent->getId(),
          'name' => $agent->getName(),
          'imageUrl' => $agent->getIcon(),
          'isReadOnly' => $agent->isReadOnly(),
          'actions' => $agentActions,
        ];
      }

      $list[] = [
        'imageUrl' => FileSystem::instance()->getUrl($model->getIcon()),
        'name' => $model->getName(),
        'id' => $model->getId(),
        'isDefault' => $model->isDefault(),
        'shortDescription' => $model->getShortDescription(),
        'isAvailable' => $model->isAvailable(),
        'isSoon' => $model->isSoon(),
        'agents' => $agents
      ];
    }

    return rest_ensure_response([
      'success' => true,
      'connected' => \FC\User::instance()->isConnected(),
      'skills' => $list
    ]);
  }

  public function localLicenseProcessor(WP_REST_Request $request): WP_REST_Response
  {
    $plugin = $request->get_param('plugin');

    set_transient('fc/local-license-processor/' . $plugin, wp_json_encode($request->get_params()), MINUTE_IN_SECONDS);

    return rest_ensure_response([
      'success' => true,
    ]);
  }

  public function beacon(): WP_REST_Response
  {
    return rest_ensure_response([
      'version' => FULL_CUSTOMER_VERSION
    ]);
  }
}

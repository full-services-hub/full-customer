<?php

namespace FC\Actions;

use FC\Services\LocalLicenseProcessor;
use WP_REST_Request;
use WP_REST_Response;

class PluginLicense extends AbstractAction
{
  public function getIcon(): string
  {
    return '';
  }

  public function getName(): string
  {
    return 'Ativar licença no plugin';
  }

  public function getShortDescription(): string
  {
    return 'Permite o usuário solicitar a ativação da licença PRO nos plugins que ele tem pela FULL.';
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
    return 'actions/plugins/license/(?P<processId>[a-zA-Z0-9-]+)';
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    $pid = preg_replace('/[^a-zA-Z0-9-]/', '', sanitize_text_field((string) ($request->get_param('processId') ?? '')));
    $slug = sanitize_text_field((string) ($request->get_param('pluginSlug') ?? ''));
    $step = sanitize_text_field((string) ($request->get_param('step') ?? ''));
    $rawState = $request->get_param('state');
    $state = is_array($rawState) ? $rawState : [];

    $cacheKey = 'fc/license-payload/' . $slug . ($pid !== '' ? '/' . $pid : '');
    $licensePayload = false;

    if ($step !== '') {
      $licensePayload = get_transient($cacheKey);
    }

    if ($licensePayload === false) {
      $data = fcDashboardAPI('POST', 'plugin-repository/' . $slug . '/license');

      if (!$data['success']) {
        delete_transient($cacheKey);
        return new WP_REST_Response([
          'success' => false,
          'error' => $data['message']
        ]);
      }

      $licensePayload = (string) ($data['data']['license'] ?? '');
      set_transient($cacheKey, $licensePayload, 5 * MINUTE_IN_SECONDS);
    }

    $processor = new LocalLicenseProcessor();
    $result = $processor->process($slug, (string) $licensePayload, $step, $state);

    $isCompleted = !isset($result['completed']) || $result['completed'] === true;

    if ($pid !== '' && !empty($result['message'])) {
      ExecutionStatus::updateState($pid, (string) $result['message']);
    }

    if (!$result['success'] || $isCompleted) {
      delete_transient($cacheKey);
    }

    if ($result['success'] && $isCompleted) {
      fcDashboardAPI('POST', 'plugin-repository/' . $slug . '/license/confirm');
      do_action('fc/updates/invalidate');
    }

    return new WP_REST_Response([
      'success' => $result['success'],
      'message' => $result['message'] ?? ($result['success'] ? 'Plugin ativado com sucesso e pronto para uso! Aproveite.' : 'A ativação automática falhou, nossa equipe técnica já foi acionada para solucionar o caso.'),
      'result'  => $result
    ]);
  }
}

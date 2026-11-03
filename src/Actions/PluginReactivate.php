<?php



namespace FC\Actions;

use FC\Services\LocalLicenseProcessor;
use FC\User;
use WP_REST_Request;
use WP_REST_Response;

class PluginReactivate extends AbstractAction
{
  private ?array $repoPlugin = null;

  public function __construct(?array $repoPlugin = null)
  {
    $this->repoPlugin = $repoPlugin;
  }

  public function getIcon(): string
  {
    return 'assets/images/icons/recover.svg';
  }

  public function getName(): string
  {
    return 'Solicitar reativação';
  }

  public function getShortDescription(): string
  {
    return 'Reativar e validar a licença do plugin localmente.';
  }

  public function showInActionsDropdown(): bool
  {
    return true;
  }

  public function getPromptArgs(): array
  {
    if (!$this->repoPlugin) {
      return $this->_defaultPromptArgs();
    }

    return array_merge($this->_defaultPromptArgs(), [
      'id' => 'reactivate.' . $this->repoPlugin['id'],
      'name' => $this->getName(),
      'desc' => $this->getShortDescription(),
      'simpleRest' => true,
      'extraProps' => [
        'plugin' => $this->repoPlugin['plugin']
      ]
    ]);
  }

  public function getRestMethod(): string
  {
    return 'POST';
  }

  public function getRestRoute(): string
  {
    $slug = $this->repoPlugin && isset($this->repoPlugin['slug']) ? $this->repoPlugin['slug'] : '(?P<pluginSlug>[a-zA-Z0-9-]+)';
    return 'actions/activation/' . $slug . '/reactivate';
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    $slug = $this->repoPlugin && isset($this->repoPlugin['slug']) ? $this->repoPlugin['slug'] : $request->get_param('pluginSlug');

    if (!$slug) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Identificador do plugin não fornecido.'
      ], 400);
    }

    $step = sanitize_text_field($request->get_param('step') ?? '');
    $state = $request->get_param('state') ?? [];

    if (empty($step)) {
      $data = fcDashboardAPI('POST', 'plugin-repository/' . $slug . '/reactivate');

      if (!$data['success']) {
        return new WP_REST_Response([
          'success' => false,
          'error' => $data['message']
        ]);
      }
    }

    $licenseData = fcDashboardAPI('POST', 'plugin-repository/' . $slug . '/license');

    if (!$licenseData['success']) {
      return new WP_REST_Response([
        'success' => false,
        'error' => $licenseData['message'] ?? 'Não foi possível recuperar os dados da licença no painel da FULL.'
      ]);
    }

    $processor = new LocalLicenseProcessor();
    $result = $processor->process($slug, $licenseData['data']['license'] ?? '', $step, $state);

    $isCompleted = !isset($result['completed']) || $result['completed'] === true;

    if ($result['success'] && $isCompleted) {
      fcDashboardAPI('POST', 'plugin-repository/' . $slug . '/license/confirm');
    }

    do_action('fc/updates/invalidate');

    return new WP_REST_Response([
      'success' => $result['success'],
      'message' => $result['message'] ?? ($result['success'] ? 'Plugin reativado com sucesso e pronto para uso! Aproveite.' : 'A ativação automática falhou, nossa equipe técnica já foi acionada para solucionar o caso.'),
      'result' => $result
    ]);
  }
}

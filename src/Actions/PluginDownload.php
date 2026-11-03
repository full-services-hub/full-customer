<?php



namespace FC\Actions;

use FC\User;
use WP_REST_Request;
use WP_REST_Response;

class PluginDownload extends AbstractAction
{
  private ?array $repoPlugin = null;

  public function __construct(?array $repoPlugin = null)
  {
    $this->repoPlugin = $repoPlugin;
  }

  public function getIcon(): string
  {
    return 'assets/images/icons/download.svg';
  }

  public function getName(): string
  {
    return 'Baixar plugin (ZIP)';
  }

  public function getShortDescription(): string
  {
    return 'Baixar o arquivo .zip do plugin diretamente no seu computador.';
  }

  public function getPromptArgs(): array
  {
    $args = $this->_defaultPromptArgs();
    $args['simpleRest'] = true;

    if ($this->repoPlugin) {
      $args = array_merge($args, [
        'id' => 'pluginDownload.' . $this->repoPlugin['id'],
        'extraProps' => [
          'pluginId' => $this->repoPlugin['id'],
          'pluginSlug' => $this->repoPlugin['slug'] ?? '',
        ]
      ]);
    }

    return $args;
  }

  public function getRestMethod(): string
  {
    return 'POST';
  }

  public function getRestRoute(): string
  {
    return 'actions/plugins/download';
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    $pluginSlug = sanitize_text_field((string) $request->get_param('pluginSlug'));

    if (empty($pluginSlug) && $this->repoPlugin) {
      $pluginSlug = (string) ($this->repoPlugin['slug'] ?? '');
    }

    $packageUrl = '';
    $pluginName = '';

    if (!empty($pluginSlug)) {
      $data = fcDashboardAPI('GET', 'plugin-repository/' . $slug = preg_replace('/[^a-zA-Z0-9-_]/', '', $pluginSlug) . '/info');
      if ($data['success'] && !empty($data['data']['package'])) {
        $packageUrl = (string) $data['data']['package'];
        $pluginName = (string) ($data['data']['name'] ?? $pluginSlug);
      }
    }

    if (empty($packageUrl) && $this->repoPlugin && !empty($this->repoPlugin['package'])) {
      $packageUrl = (string) $this->repoPlugin['package'];
      $pluginName = (string) ($this->repoPlugin['name'] ?? '');
    }

    if (empty($packageUrl)) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Não foi possível obter o link de download para este plugin.'
      ], 400);
    }

    $userEmail = User::instance()->getConnectionEmail();
    if (!empty($userEmail)) {
      $packageUrl = add_query_arg('userEmail', $userEmail, $packageUrl);
    }

    $displayName = !empty($pluginName) ? $pluginName : 'Plugin';
    $message = sprintf(
      'O link para download do <strong>%s</strong> foi gerado com sucesso! Clique no botão abaixo para iniciar o download.',
      esc_html($displayName)
    );

    return new WP_REST_Response([
      'success' => true,
      'message' => $message,
      'actions' => [
        [
          'label' => 'Baixar ' . esc_html($displayName),
          'action' => 'download',
          'url' => $packageUrl,
          'download' => true,
        ]
      ]
    ]);
  }
}

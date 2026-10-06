<?php

declare(strict_types=1);

namespace FC\Actions;

use FC\Services\BackLink as BackLinkService;
use WP_REST_Request;
use WP_REST_Response;

class Backlink extends AbstractAction
{
  public function getId(): string
  {
    return 'backlink';
  }

  public function getIcon(): string
  {
    return 'assets/images/icons/link.svg';
  }

  public function getName(): string
  {
    return 'Backlink FULL.';
  }

  public function getShortDescription(): string
  {
    return "Alternar status do backlink transparente";
  }

  public function getPromptArgs(): array
  {
    return array_merge($this->_defaultPromptArgs(), [
      'id' => 'backlink',
      'simpleRest' => true,
    ]);
  }

  public function getRestMethod(): string
  {
    return 'POST';
  }

  public function getRestRoute(): string
  {
    return 'actions/dev/backlink';
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    $statusParam = $request->get_param('status');

    if ($statusParam !== null) {
      $enabled = filter_var($statusParam, FILTER_VALIDATE_BOOLEAN);
      BackLinkService::setEnabled($enabled);
    } else {
      $enabled = BackLinkService::toggle();
    }

    $message = $enabled
      ? 'O status do backlink foi atualizado com sucesso! O backlink está **ativado** e sendo exibido no rodapé do site.'
      : 'O status do backlink foi atualizado com sucesso! O backlink está **desativado** e não será exibido no rodapé do site.';

    return new WP_REST_Response([
      'success' => true,
      'message' => $message,
      'response' => [
        'enabled' => $enabled,
      ],
      'actions' => [
        [
          'label' => $enabled ? 'Desativar backlink' : 'Ativar backlink',
          'action' => 'backlink',
        ],
      ],
    ]);
  }
}

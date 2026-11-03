<?php



namespace FC\Actions;

use FC\FileSystem;
use WP_REST_Request;
use WP_REST_Response;

class PluginUploadChunk extends AbstractAction
{
  public function getIcon(): string
  {
    return '';
  }

  public function getName(): string
  {
    return 'Enviar pedaço do plugin';
  }

  public function getShortDescription(): string
  {
    return 'Recebe pedaços de arquivo de plugin, remonta e instala após a conclusão.';
  }

  public function getPromptArgs(): array
  {
    return array_merge($this->_defaultPromptArgs(), []);
  }

  public function getRestMethod(): string
  {
    return 'POST';
  }

  public function showInActionsDropdown(): bool
  {
    return false;
  }

  public function getRestRoute(): string
  {
    return 'actions/plugins/upload-chunk/(?P<processId>[a-zA-Z0-9-]+)';
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $pid = preg_replace('/[^a-zA-Z0-9-]/', '', sanitize_text_field($request->get_param('processId')));
    $pluginSlug = preg_replace('/[^a-zA-Z0-9-_]/', '', sanitize_text_field($request->get_param('pluginSlug')));
    $chunkIndex = intval($request->get_param('chunkIndex'));
    $totalChunks = intval($request->get_param('totalChunks'));

    if (empty($pid) || empty($pluginSlug)) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Processo ou plugin inválido.'
      ], 400);
    }

    $files = $request->get_file_params();
    if (empty($files['chunk'])) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Nenhum chunk de arquivo foi enviado.'
      ], 400);
    }

    $chunkFile = $files['chunk'];
    $fs = FileSystem::instance();

    $tempDir = $fs->wpContentDir() . 'upgrade';
    if (!$fs->isDir($tempDir)) {
      $fs->mkdir($tempDir);
    }

    $finalFilePath = $tempDir . '/' . $pluginSlug . '-' . $pid . '.zip';

    // Se for o primeiro chunk, remove qualquer resquício de instalação falha anterior
    if ($chunkIndex === 0 && $fs->isFile($finalFilePath)) {
      $fs->delete($finalFilePath);
    }

    // Grava os dados do chunk usando concatenação (FILE_APPEND)
    $chunkData = file_get_contents($chunkFile['tmp_name']);
    if ($chunkData === false) {
      return new WP_REST_Response([
        'success' => false,
        'error' => 'Falha ao ler dados do chunk temporário.'
      ], 500);
    }

    $fs->appendContents($finalFilePath, $chunkData);

    // Se for o último chunk, finaliza a montagem do arquivo e retorna sucesso de upload
    if ($chunkIndex === $totalChunks - 1) {
      return new WP_REST_Response([
        'success' => true,
        'completed' => true,
        'message' => 'Upload do arquivo de instalação concluído.'
      ]);
    }

    // Retorna progresso intermediário
    return new WP_REST_Response([
      'success' => true,
      'completed' => false,
      'message' => "Pedaço {$chunkIndex} enviado com sucesso."
    ]);
  }
}

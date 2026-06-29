<?php

/**
 * @file
 * OpenRouter adapter for accessing 200+ AI models.
 *
 * Image generation note:
 * - Use /api/v1/chat/completions with "modalities": ["image","text"]
 * - Responses usually contain a data URL at choices[0].message.images[0].image_url.url
 * - We DO NOT stream for images; we need the final JSON to extract the data URL.
 */

class AIOpenRouterAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  const BASE_URL = 'https://openrouter.ai/api/v1';

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->apiKey,
      'HTTP-Referer'  => url('<front>', ['absolute' => TRUE]),
      'X-Title'       => config_get('system.core', 'site_name') ?: 'Backdrop CMS',
    ];
  }

  /** ------------------------ Models ------------------------ */

  public function getModels(): array {
    $models = [];
    try {
      $model_data = $this->fetchModelData();

      $models_with_provider = [];
      foreach ($model_data as $model) {
        $id = $model['id'] ?? '';
        $name = $model['name'] ?? $id;
        if (!$id) {
          continue;
        }
        $provider = '';
        if (strpos($id, '/') !== FALSE) {
          list($provider) = explode('/', $id, 2);
          $provider = ucfirst($provider);
        }
        $models_with_provider[$id] = [
          'name'     => $name,
          'provider' => $provider,
        ];
      }

      uasort($models_with_provider, function ($a, $b) {
        $provider_cmp = strcmp($a['provider'], $b['provider']);
        if ($provider_cmp !== 0) {
          return $provider_cmp;
        }
        return strcmp($a['name'], $b['name']);
      });

      foreach ($models_with_provider as $id => $info) {
        $models[$id] = $info['name'];
      }

      $enabled_models = config_get('ai_provider_openrouter.settings', 'enabled_models');
      if (isset($enabled_models) && is_array($enabled_models)) {
        if (empty($enabled_models)) {
          return [];
        }
        $filtered = [];
        foreach ($models as $id => $name) {
          if (in_array($id, $enabled_models)) {
            $filtered[$id] = $name;
          }
        }
        return $filtered;
      }
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'Failed to fetch OpenRouter models: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
    }
    return $models;
  }

  protected function fetchModelData(): array {
    $cache_key = 'openrouter_model_data';
    $cached = cache_get($cache_key);
    if ($cached && !empty($cached->data)) {
      return $cached->data;
    }

    try {
      $data = $this->makeRequest(self::BASE_URL . '/models', [], [], 'GET', 10);
      $model_data = $data['data'] ?? [];
      cache_set($cache_key, $model_data, 'cache', time() + 3600);
      return $model_data;
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'Failed to fetch OpenRouter model data: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   *
   * OpenRouter's capability metadata is unreliable — some models misreport
   * or omit their supported modalities. All models are returned for any
   * capability. Site admins can override via hook_ai_model_capabilities_alter().
   */
  public function getModelsByCapability($capability): array {
    $models = $this->getModels();
    backdrop_alter('ai_model_capabilities', $models, $capability, $this);
    return $models;
  }

  /** ------------------------ Text / Chat ------------------------ */

  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    try {
      $payload = [
        'model'       => $model,
        'prompt'      => trim($prompt),
        'temperature' => (float) $temperature,
        'max_tokens'  => max(1, min((int) $max_tokens ?: 1024, 8192)),
      ];

      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse(self::BASE_URL . '/completions', [
          'method'  => 'POST',
          'headers' => array_merge(
            ['Accept' => 'text/event-stream', 'Content-Type' => 'application/json'],
            $this->getDefaultHeaders()
          ),
          'data'    => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          return $data['choices'][0]['delta']['content'] ?? $data['choices'][0]['text'] ?? '';
        });
      }

      $result = $this->makeRequest(self::BASE_URL . '/completions', $payload, [], 'POST', 300);
      return trim($result['choices'][0]['text'] ?? '');
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'OpenRouter completions error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    try {
      if (function_exists('backdrop_alter') && empty($context_extra['skip_ai_message_alter'])) {
        $context = [
          'operation' => 'chat',
          'model'     => $model,
          'provider'  => 'openrouter',
        ];
        backdrop_alter('ai_chat_messages', $messages, $context);
      }

      $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => (float) $temperature,
        'max_tokens'  => max(1, min((int) $max_tokens ?: 1024, 8192)),
      ];

      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse(self::BASE_URL . '/chat/completions', [
          'method'  => 'POST',
          'headers' => array_merge(
            ['Accept' => 'text/event-stream', 'Content-Type' => 'application/json'],
            $this->getDefaultHeaders()
          ),
          'data'    => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          return $data['choices'][0]['delta']['content'] ?? '';
        });
      }

      // Use backdrop_http_request directly here so the 400 retry path can
      // inspect the response body before deciding whether to retry.
      $options = [
        'method'  => 'POST',
        'headers' => array_merge(
          ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
          $this->getDefaultHeaders()
        ),
        'data'    => json_encode($payload),
        // Long generations can exceed 60s; match the streaming path's 300s.
        'timeout' => 300,
      ];

      $response  = backdrop_http_request(self::BASE_URL . '/chat/completions', $options);
      $http_code = (int) ($response->code ?? 0);

      if ($http_code === 200) {
        $result = json_decode($response->data, TRUE);
        return trim($result['choices'][0]['message']['content'] ?? '');
      }

      // Some models return 400 when system/developer instructions are disabled; retry without them.
      if ($http_code === 400) {
        $error_data = json_decode($response->data, TRUE);
        $top_msg    = $error_data['error']['message'] ?? '';
        $raw_msg    = $error_data['error']['metadata']['raw'] ?? '';
        $needle     = 'Developer instruction is not enabled';

        if (stripos($top_msg, $needle) !== FALSE || stripos($raw_msg, $needle) !== FALSE) {
          $messages_no_system = [];
          foreach ($messages as $message) {
            if (isset($message['role']) && $message['role'] === 'system') {
              $messages_no_system[] = [
                'role'    => 'user',
                'content' => '[Instructions]: ' . $message['content'],
              ];
            }
            else {
              $messages_no_system[] = $message;
            }
          }

          $payload['messages'] = $messages_no_system;
          $options['data'] = json_encode($payload);

          $response2  = backdrop_http_request(self::BASE_URL . '/chat/completions', $options);
          $http_code2 = (int) ($response2->code ?? 0);

          if ($http_code2 === 200) {
            $result = json_decode($response2->data, TRUE);
            return trim($result['choices'][0]['message']['content'] ?? '');
          }
        }
      }

      throw new \Exception('HTTP ' . $http_code . ': ' . $this->formatErrorBody($response));
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'OpenRouter chat error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /** ------------------------ Image Generation ------------------------ */

  protected function mapSizeToAspectRatio(string $size): ?string {
    switch ($size) {
      case '1024x1024': return '1:1';
      case '1792x1024': return '16:9';
      case '1024x1792': return '9:16';
      case '1248x832':  return '3:2';
      case '832x1248':  return '2:3';
      default:          return NULL;
    }
  }

  /**
   * Generate an image via OpenRouter chat/completions with modalities.
   *
   * Returns: ['data' => [ ['url' => 'data:image/png;base64,...'] ]]
   *       or ['data' => [ ['b64_json' => '...'] ]]
   */
  public function images(
    string $model,
    string $prompt,
    string $size = '1024x1024',
    string $response_format = 'url',
    string $quality = 'standard',
    string $style = 'natural',
    ?string $output_format = NULL
  ) {
    $system_prompt = config_get('ai.settings', 'images_system_prompt')
      ?: config_get('ai.settings', 'image_system_prompt')
      ?: 'You are an image generation model. When responding, produce an image and include either a data URL (data:image/...) or an image object in the response. Do not return only text.';

    $messages = [
      ['role' => 'system', 'content' => $system_prompt],
      ['role' => 'user', 'content' => $prompt],
    ];

    $payload = [
      'model'      => $model,
      'messages'   => $messages,
      'modalities' => ['image', 'text'],
      'max_tokens' => 2048,
    ];
    if ($aspect = $this->mapSizeToAspectRatio($size)) {
      $payload['image_config'] = ['aspect_ratio' => $aspect];
    }

    try {
      $result = $this->makeRequest(self::BASE_URL . '/chat/completions', $payload, [], 'POST', 120);

      $message = $result['choices'][0]['message'] ?? [];
      $dataUrl = $message['images'][0]['image_url']['url'] ?? NULL;

      if (!$dataUrl) {
        $found = $this->searchForImageInResponse($result);
        if (!empty($found['url'])) {
          $dataUrl = $found['url'];
        }
        elseif (!empty($found['b64_json'])) {
          return ['data' => [['b64_json' => $found['b64_json']]]];
        }
      }

      if (!$dataUrl) {
        return ['data' => []];
      }

      if (is_string($dataUrl) && strpos($dataUrl, 'data:image/') === 0) {
        $comma = strpos($dataUrl, ',');
        $b64   = ($comma !== FALSE) ? substr($dataUrl, $comma + 1) : '';
        if ($response_format === 'b64_json') {
          return ['data' => [['b64_json' => $b64]]];
        }
        return ['data' => [['url' => $dataUrl]]];
      }

      if (filter_var($dataUrl, FILTER_VALIDATE_URL)) {
        return ['data' => [['url' => $dataUrl]]];
      }

      return ['data' => []];
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'OpenRouter images() failed: @err', ['@err' => $e->getMessage()], WATCHDOG_WARNING);
      return ['data' => []];
    }
  }

  /**
   * Recursively search a response structure for an image URL or base64 blob.
   *
   * @param mixed $data
   *
   * @return array
   *   Array with 'url' and/or 'b64_json' keys.
   */
  protected function searchForImageInResponse($data): array {
    $out = ['url' => NULL, 'b64_json' => NULL];

    if (is_string($data)) {
      if (filter_var(trim($data), FILTER_VALIDATE_URL)) {
        $out['url'] = trim($data);
        return $out;
      }
      if (preg_match('/https?:\/\/[^\s)\"]+\.(png|jpg|jpeg|webp|gif)/i', $data, $m)) {
        $out['url'] = $m[0];
        return $out;
      }
      if (strpos($data, 'data:image/') === 0) {
        $out['url'] = $data;
        return $out;
      }
      if (preg_match('/data:image\/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+\/=\n\r]+)/i', $data, $m2)) {
        $out['b64_json'] = $m2[2];
        return $out;
      }
      return $out;
    }

    if (is_array($data)) {
      $container_keys = ['images', 'outputs', 'output', 'artifacts', 'data', 'result', 'response', 'choices', 'content'];
      foreach ($container_keys as $ck) {
        if (isset($data[$ck])) {
          $found = $this->searchForImageInResponse($data[$ck]);
          if ($found['url'] || $found['b64_json']) {
            return $found;
          }
        }
      }
      foreach ($data as $k => $v) {
        $lk = strtolower((string) $k);
        if (is_string($v)) {
          if (strpos($lk, 'b64') !== FALSE || strpos($lk, 'base64') !== FALSE) {
            if (preg_match('/([A-Za-z0-9+\/=\n\r]{100,})/', $v, $m3)) {
              $out['b64_json'] = $m3[1];
              return $out;
            }
          }
          if (strpos($lk, 'url') !== FALSE || strpos($lk, 'link') !== FALSE) {
            $is_url = filter_var(trim($v), FILTER_VALIDATE_URL) || strpos($v, 'data:image/') === 0;
            if ($is_url) {
              $out['url'] = trim($v);
              return $out;
            }
          }
        }
        if (is_array($v) || is_string($v)) {
          $found = $this->searchForImageInResponse($v);
          if ($found['url'] || $found['b64_json']) {
            return $found;
          }
        }
      }
    }

    return $out;
  }

  /** ------------------------ Audio / Embeddings / Moderation ------------------------ */

  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    try {
      return $this->requestRaw(self::BASE_URL . '/audio/speech', [
        'model'           => $model,
        'voice'           => $voice,
        'input'           => $input,
        'response_format' => $response_format,
      ], [], 'POST', 60);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'OpenRouter TTS error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    if (!in_array($task, ['transcribe', 'translate'], TRUE)) {
      throw new \InvalidArgumentException('Task must be transcribe or translate.');
    }
    // OpenRouter only exposes /audio/transcriptions; there is no
    // /audio/translations endpoint.
    if ($task === 'translate') {
      watchdog('ai_provider_openrouter', 'Audio translation is not supported by OpenRouter.', [], WATCHDOG_WARNING);
      throw new \RuntimeException('Audio translation is not supported by OpenRouter.');
    }
    try {
      $fields = [
        'model'           => $model,
        'temperature'     => (string) $temperature,
        'response_format' => $response_format,
        'file'            => ['path' => $file],
      ];
      $result = $this->makeMultipartRequest(self::BASE_URL . '/audio/transcriptions', $fields, 120);
      return $result['text'] ?? '';
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'OpenRouter STT error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    // OpenRouter has no /moderations endpoint.
    watchdog('ai_provider_openrouter', 'Moderation is not supported by OpenRouter.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Moderation is not supported by OpenRouter.');
  }

  public function embedding(string $input, string $model, bool $log = TRUE): array {
    $start_time = microtime(TRUE);
    try {
      $response = $this->makeRequest(self::BASE_URL . '/embeddings', [
        'model' => $model,
        'input' => $input,
      ]);
      $result = $response['data'][0]['embedding'] ?? [];
      if (isset($this->api) && method_exists($this->api, 'recordLog')) {
        $duration = microtime(TRUE) - $start_time;
        $this->api->recordLog('embedding', $model, ['input' => $input], $response, TRUE, $duration, NULL, !$log);
      }
      return $result;
    }
    catch (\Exception $e) {
      if (isset($this->api) && method_exists($this->api, 'recordLog')) {
        $duration = microtime(TRUE) - $start_time;
        $this->api->recordLog('embedding', $model, ['input' => $input], NULL, FALSE, $duration, $e->getMessage(), !$log);
      }
      ai_log_embedding_error('ai_provider_openrouter', $e->getMessage(), $log);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    try {
      $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'tools'       => $tools,
        'tool_choice' => $tool_choice,
        'temperature' => (float) $temperature,
        'max_tokens'  => max(1, (int) $max_tokens ?: 1024),
      ];

      $data = $this->makeRequest(self::BASE_URL . '/chat/completions', $payload, [], 'POST', 300);
      return $this->normalizeToolResponse($data);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_openrouter', 'chatWithTools error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

}

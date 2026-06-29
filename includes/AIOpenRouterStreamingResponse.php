<?php

/**
 * @file
 * OpenRouter-specific streaming response with developer-instruction retry.
 */
class AIOpenRouterStreamingResponse extends AIStreamingResponse {

  private $retry_options;

  private static $dev_instruction_needle = 'Developer instruction is not enabled';

  public function __construct(string $url, array $options, callable $extractor, ?array $retry_options) {
    parent::__construct($url, $options, $extractor);
    $this->retry_options = $retry_options;
  }

  /**
   * Send the streaming response, retrying once for the developer-instruction error.
   *
   * sendStreaming() embeds the error body in the exception message, so we can
   * detect the OpenRouter-specific 400 there. sendBuffered() does not, so it
   * is overridden separately below.
   */
  public function send(): void {
    try {
      parent::send();
    }
    catch (\Exception $e) {
      if ($this->retry_options !== NULL && stripos($e->getMessage(), self::$dev_instruction_needle) !== FALSE) {
        $this->options = $this->retry_options;
        $this->retry_options = NULL;
        parent::send();
        return;
      }
      throw $e;
    }
  }

  /**
   * Buffered fallback: inspect the raw body to detect the 400 before throwing.
   *
   * The base class throws without body details, so retry detection must happen
   * here rather than in the send() catch above.
   */
  protected function sendBuffered(): void {
    if ($this->retry_options === NULL) {
      parent::sendBuffered();
      return;
    }

    $response = backdrop_http_request($this->url, $this->options);
    $http_code = (int) ($response->code ?? 0);

    if ($http_code === 400) {
      $body = (string) ($response->data ?? '');
      if (stripos($body, self::$dev_instruction_needle) !== FALSE) {
        $this->options = $this->retry_options;
        $this->retry_options = NULL;
        parent::sendBuffered();
        return;
      }
    }

    if ($http_code !== 200) {
      throw new \Exception('Streaming API error: ' . ($response->code ?? 'unknown'));
    }

    if (!isset($response->data) || !is_string($response->data)) {
      return;
    }

    foreach (explode("\n", $response->data) as $line) {
      if ($this->processLine(rtrim($line, "\r"))) {
        break;
      }
    }
  }

}

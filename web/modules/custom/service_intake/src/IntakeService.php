<?php

namespace Drupal\service_intake;

/**
 * Classifies citizen service requests via an injected AI chat callable.
 */
class IntakeService {

  public const SYSTEM_PROMPT = <<<PROMPT
You are a classifier for Alberta government services. Given a plain-language service request,
respond with ONLY a raw JSON object — no markdown, no code fences, no explanation.
The JSON must have exactly these fields:
- ministry: the responsible Alberta ministry (string)
- next_steps: a plain-language summary of what the citizen should do next (string)
- confidence: your confidence in the classification, from 0.0 to 1.0 (float)
PROMPT;

  /**
   * Parse the raw classifier response into structured data.
   *
   * @param string $raw
   *   The raw AI response text.
   *
   * @return array
   *   The decoded JSON response or an error payload.
   */
  public function parseResponse(string $raw): array {
    // Strip markdown fences defensively.
    $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $clean = preg_replace('/\s*```$/', '', $clean);

    $data = json_decode($clean, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return ['error' => 'parse_failure', 'raw' => $raw];
    }

    foreach (['ministry', 'next_steps', 'confidence'] as $field) {
      if (!isset($data[$field])) {
        return ['error' => 'missing_field:' . $field, 'raw' => $raw];
      }
    }

    return $data;
  }

  /**
   * Apply a confidence threshold to the classifier result.
   *
   * @param array $result
   *   The parsed classification result.
   * @param float $threshold
   *   The minimum confidence required to avoid review.
   *
   * @return array
   *   The result with a needs_review flag added.
   */
  public function applyThreshold(array $result, float $threshold): array {
    $result['needs_review'] = isset($result['error'])
        || $result['confidence'] < $threshold;
    return $result;
  }

}

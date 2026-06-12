<?php

namespace Drupal\service_intake\Plugin\WebformHandler;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a webform handler for intake classification.
 *
 * @WebformHandler(
 *   id = "intake_classifier",
 *   label = @Translation("Intake Classifier"),
 *   category = @Translation("AI"),
 *   description = @Translation("Classifies service requests using Claude."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class IntakeClassifierHandler extends WebformHandlerBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $aiProvider;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['confidence_threshold' => 0.85];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['confidence_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Confidence threshold'),
      '#default_value' => $this->configuration['confidence_threshold'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['confidence_threshold'] = $form_state->getValue('confidence_threshold');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->aiProvider = $container->get('ai.provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $operation = 'insert'): void {
    $request_text = $webform_submission->getElementData('service_request');
    $threshold = $this->configuration['confidence_threshold'];

    $system_prompt = <<<PROMPT
You are a classifier for Alberta government services. Given a plain-language service request,
respond with ONLY a raw JSON object — no markdown, no code fences, no explanation.
The JSON must have exactly these fields:
- ministry: the responsible Alberta ministry (string)
- next_steps: a plain-language summary of what the citizen should do next (string)
- confidence: your confidence in the classification, from 0.0 to 1.0 (float)
PROMPT;

    $result = NULL;
    try {
      $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
      if ($default === NULL) {
        \Drupal::logger('service_intake')->error('No AI provider available for chat operations.');
        return;
      }

      $provider = $this->aiProvider->createInstance($default['provider_id']);
      $messages = new ChatInput([
        new ChatMessage('system', $system_prompt),
        new ChatMessage('user', $request_text),
      ]);
      $raw = $provider->chat($messages, $default['model_id'])->getNormalized()->getText();
      $result = json_decode(self::stripMarkdownFences($raw), FALSE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Exception $e) {
      \Drupal::logger('service_intake')->error('Claude API call failed: @message', ['@message' => $e->getMessage()]);
    }

    if (!is_object($result)) {
      return;
    }

    $flagged = $result->confidence < $threshold;

    $webform_submission->setElementData('ministry', $result->ministry);
    $webform_submission->setElementData('next_steps', $result->next_steps);
    $webform_submission->setElementData('confidence', $result->confidence);
    $webform_submission->setElementData('needs_review', $flagged);
    $webform_submission->resave();
  }

  protected static function stripMarkdownFences(string $raw): string {
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    return preg_replace('/\s*```$/', '', $raw);
  }

}

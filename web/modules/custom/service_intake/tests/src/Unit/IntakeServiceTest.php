<?php

namespace Drupal\Tests\service_intake\Unit;

use Drupal\service_intake\IntakeService;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\service_intake\IntakeService
 * @group service_intake
 */
class IntakeServiceTest extends TestCase {

  private IntakeService $service;

  protected function setUp(): void {
    $this->service = new IntakeService();
  }

  // ---------------------------------------------------------------------------
  // parseResponse()
  // ---------------------------------------------------------------------------

  /**
   * @covers ::parseResponse
   */
  public function testParseResponseValidJson(): void {
    $raw = '{"ministry":"Health","next_steps":"Visit alberta.ca/health","confidence":0.92}';
    $result = $this->service->parseResponse($raw);
    $this->assertSame('Health', $result['ministry']);
    $this->assertSame('Visit alberta.ca/health', $result['next_steps']);
    $this->assertSame(0.92, $result['confidence']);
  }

  /**
   * @covers ::parseResponse
   */
  public function testParseResponseStripsJsonFence(): void {
    $raw = "```json\n{\"ministry\":\"Health\",\"next_steps\":\"Visit alberta.ca\",\"confidence\":0.9}\n```";
    $result = $this->service->parseResponse($raw);
    $this->assertSame('Health', $result['ministry']);
  }

  /**
   * @covers ::parseResponse
   */
  public function testParseResponseStripsGenericFence(): void {
    $raw = "```\n{\"ministry\":\"Health\",\"next_steps\":\"Visit alberta.ca\",\"confidence\":0.9}\n```";
    $result = $this->service->parseResponse($raw);
    $this->assertSame('Health', $result['ministry']);
  }

  /**
   * @covers ::parseResponse
   */
  public function testParseResponseInvalidJsonReturnsErrorPayload(): void {
    $result = $this->service->parseResponse('not valid json');
    $this->assertArrayHasKey('error', $result);
    $this->assertSame('parse_failure', $result['error']);
  }

  /**
   * @covers ::parseResponse
   */
  public function testParseResponseEmptyStringReturnsErrorPayload(): void {
    $result = $this->service->parseResponse('');
    $this->assertArrayHasKey('error', $result);
  }

  /**
   * @covers ::parseResponse
   */
  public function testParseResponseMissingFieldReturnsErrorPayload(): void {
    $raw = '{"ministry":"Health","confidence":0.9}';
    $result = $this->service->parseResponse($raw);
    $this->assertArrayHasKey('error', $result);
    $this->assertStringStartsWith('missing_field:', $result['error']);
  }

  /**
   * @covers ::parseResponse
   */
  public function testParseResponseNoErrorKeyOnSuccess(): void {
    $raw = '{"ministry":"Health","next_steps":"Visit alberta.ca","confidence":0.9}';
    $result = $this->service->parseResponse($raw);
    $this->assertArrayNotHasKey('error', $result);
  }

  // ---------------------------------------------------------------------------
  // applyThreshold()
  // ---------------------------------------------------------------------------

  /**
   * @covers ::applyThreshold
   */
  public function testHighConfidenceIsNotFlagged(): void {
    $result = $this->service->applyThreshold(
      ['ministry' => 'Health', 'next_steps' => 'Visit alberta.ca', 'confidence' => 0.9],
      0.85,
    );
    $this->assertFalse($result['needs_review']);
  }

  /**
   * @covers ::applyThreshold
   */
  public function testLowConfidenceIsFlagged(): void {
    $result = $this->service->applyThreshold(
      ['ministry' => 'Health', 'next_steps' => 'Visit alberta.ca', 'confidence' => 0.6],
      0.85,
    );
    $this->assertTrue($result['needs_review']);
  }

  /**
   * Confidence exactly at the threshold should pass (≥, not >).
   *
   * @covers ::applyThreshold
   */
  public function testBoundaryConfidenceIsNotFlagged(): void {
    $result = $this->service->applyThreshold(
      ['ministry' => 'Health', 'next_steps' => 'Visit alberta.ca', 'confidence' => 0.85],
      0.85,
    );
    $this->assertFalse($result['needs_review']);
  }

  /**
   * @covers ::applyThreshold
   */
  public function testJustBelowBoundaryIsFlagged(): void {
    $result = $this->service->applyThreshold(
      ['ministry' => 'Health', 'next_steps' => 'Visit alberta.ca', 'confidence' => 0.84],
      0.85,
    );
    $this->assertTrue($result['needs_review']);
  }

  /**
   * An error payload must always be flagged for review regardless of threshold.
   *
   * @covers ::applyThreshold
   */
  public function testErrorPayloadIsAlwaysFlagged(): void {
    $result = $this->service->applyThreshold(
      ['error' => 'parse_failure', 'raw' => 'garbage'],
      0.0,
    );
    $this->assertTrue($result['needs_review']);
  }

}

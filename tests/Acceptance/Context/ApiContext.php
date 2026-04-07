<?php

namespace Tests\Acceptance\Context;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * Behat API context for Snipe-IT REST API acceptance tests.
 * Course MGL842 — Course 08 (ATDD / Gherkin)
 */
class ApiContext implements Context
{
    private Client $client;
    private ?ResponseInterface $response = null;
    private array $responseBody = [];
    private string $baseUrl;
    private string $apiToken = '';

    public function __construct(string $baseUrl = 'http://localhost:8000')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client  = new Client([
            'base_uri'        => $this->baseUrl,
            'timeout'         => 10,
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * @Given I am authenticated as an admin via API token
     */
    public function iAmAuthenticatedAsAnAdminViaApiToken(): void
    {
        $this->apiToken = getenv('SNIPEIT_API_TOKEN') ?: 'test-token';
    }

    /**
     * @Given I am not authenticated
     */
    public function iAmNotAuthenticated(): void
    {
        $this->apiToken = '';
    }

    /**
     * @Given I am authenticated as a regular user
     */
    public function iAmAuthenticatedAsARegularUser(): void
    {
        $this->apiToken = getenv('SNIPEIT_USER_TOKEN') ?: 'user-test-token';
    }

    // ── HTTP Requests ─────────────────────────────────────────────────────────

    /**
     * @When I send a GET request to :path
     */
    public function iSendAGetRequestTo(string $path): void
    {
        $this->response     = $this->client->get($path, $this->buildOptions());
        $this->responseBody = $this->parseBody();
    }

    /**
     * @When I send a POST request to :path with body:
     */
    public function iSendAPostRequestToWithBody(string $path, PyStringNode $body): void
    {
        $options = array_merge($this->buildOptions(), [
            'json' => json_decode($body->getRaw(), true),
        ]);
        $this->response     = $this->client->post($path, $options);
        $this->responseBody = $this->parseBody();
    }

    // ── Assertions ────────────────────────────────────────────────────────────

    /**
     * @Then the response status code should be :code
     */
    public function theResponseStatusCodeShouldBe(int $code): void
    {
        Assert::assertEquals(
            $code,
            $this->response->getStatusCode(),
            "Expected HTTP {$code}, got {$this->response->getStatusCode()}. Body: " . $this->response->getBody()
        );
    }

    /**
     * @Then the response should contain a :key array
     */
    public function theResponseShouldContainAArray(string $key): void
    {
        Assert::assertArrayHasKey($key, $this->responseBody, "Response missing key: {$key}");
        Assert::assertIsArray($this->responseBody[$key], "Response key '{$key}' is not an array");
    }

    /**
     * @Then the response should contain a :key field
     */
    public function theResponseShouldContainAField(string $key): void
    {
        Assert::assertArrayHasKey($key, $this->responseBody, "Response missing key: {$key}");
    }

    /**
     * @Then the response payload should contain :key equal to :value
     */
    public function theResponsePayloadShouldContainEqualTo(string $key, string $value): void
    {
        Assert::assertArrayHasKey($key, $this->responseBody, "Response missing key: {$key}");
        Assert::assertEquals($value, $this->responseBody[$key], "Expected '{$key}' = '{$value}', got '{$this->responseBody[$key]}'");
    }

    /**
     * @Then the response payload should contain validation messages
     */
    public function theResponsePayloadShouldContainValidationMessages(): void
    {
        Assert::assertArrayHasKey('messages', $this->responseBody, "Response missing validation messages");
        Assert::assertNotEmpty($this->responseBody['messages'], "Validation messages should not be empty");
    }

    /**
     * @Then the response should contain a :key array with at least :count item(s)
     */
    public function theResponseShouldContainAnArrayWithAtLeastItems(string $key, int $count): void
    {
        Assert::assertArrayHasKey($key, $this->responseBody);
        Assert::assertGreaterThanOrEqual($count, count($this->responseBody[$key]));
    }

    /**
     * @Then the created asset should have :key equal to :value
     */
    public function theCreatedAssetShouldHaveEqualTo(string $key, string $value): void
    {
        $payload = $this->responseBody['payload'] ?? $this->responseBody;
        Assert::assertEquals($value, $payload[$key] ?? null);
    }

    /**
     * @Then the created user should have :key equal to :value
     */
    public function theCreatedUserShouldHaveEqualTo(string $key, string $value): void
    {
        $payload = $this->responseBody['payload'] ?? $this->responseBody;
        Assert::assertEquals($value, $payload[$key] ?? null);
    }

    // ── Preconditions ─────────────────────────────────────────────────────────

    /**
     * @Given asset with ID :id exists in the system
     */
    public function assetWithIdExistsInTheSystem(int $id): void
    {
        // Precondition check — can be seeded via factory in a real test
    }

    /**
     * @Given a valid asset category exists
     * @Given a valid asset model exists
     * @Given a valid status label exists
     */
    public function aValidResourceExists(): void
    {
        // Seed data exists via database seeders
    }

    /**
     * @Given asset :tag exists and has status :status
     */
    public function assetExistsAndHasStatus(string $tag, string $status): void
    {
        // Precondition satisfied by seeded data
    }

    /**
     * @Given user with ID :id exists
     * @Given user :username exists
     */
    public function userExists(string $idOrUsername): void
    {
        // Precondition satisfied by seeded data
    }

    /**
     * @Given asset with ID :id is currently checked out
     */
    public function assetIsCurrentlyCheckedOut(int $id): void
    {
        // Precondition satisfied by prior scenario steps
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function buildOptions(): array
    {
        $options = [
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if ($this->apiToken !== '') {
            $options['headers']['Authorization'] = "Bearer {$this->apiToken}";
        }

        return $options;
    }

    private function parseBody(): array
    {
        $body = (string) $this->response->getBody();
        if (empty($body)) {
            return [];
        }
        return json_decode($body, true) ?? [];
    }
}

# Drupal AI — Citizen Service Intake Classifier

A Drupal 11 project that accepts plain-language service requests, classifies them to the correct ministry using Claude, generates next-step summaries, and routes results based on a confidence score.

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- [DDEV](https://ddev.com/get-started/)
- An [Anthropic API key](https://console.anthropic.com/)

## Quick start

```bash
git clone <repo-url>
cd drupal-ai
cp .ddev/config.local.yaml.example .ddev/config.local.yaml
# Edit .ddev/config.local.yaml and add your Anthropic API key
ddev start
ddev import-db --file=db-init/init.sql.gz
ddev launch
```

Log in with **admin / admin**.

## Stopping the project

```bash
ddev stop
```

## Resetting to a clean state

```bash
ddev delete -O
ddev start
ddev import-db --file=db-init/init.sql.gz
```

## Services

| Service | URL |
|---|---|
| Drupal | https://drupal-ai.ddev.site |
| Mailpit | https://drupal-ai.ddev.site:8026 |

---

## Development

### Architecture

The feature is built from four layers:

1. **Webform** — collects the plain-language service request
2. **Webform Handler** (custom module) — fires on submission, calls Claude via the AI module
3. **Claude** — classifies the request, returns ministry, next steps, and a confidence score
4. **Twig template** — renders the result; high-confidence requests are auto-routed, low-confidence ones are flagged for human review

### Step 1 — Install contrib modules

```bash
ddev exec composer require drupal/ai drupal/ai_provider_anthropic drupal/key "drupal/webform:^6.3@beta"
ddev exec drush en ai ai_provider_anthropic key webform webform_ui -y
```

> **Note:** Webform 6.3 is in beta pending a stable Drupal 11 release. Remove the `@beta` flag and run `composer update drupal/webform` once 6.3.0 stable is out.

### Step 2 — Store the API key

The API key is kept out of the database and out of the repo by reading it from an environment variable.

**2a — Create `.ddev/config.local.yaml`** (gitignored, never committed):

```yaml
web_environment:
  - ANTHROPIC_API_KEY=sk-ant-your-real-key-here
```

Then restart DDEV to pick it up:

```bash
ddev restart
```

**2b — Configure the Key module**

1. Go to **Admin → Configuration → System → Keys → Add key**
2. Set *Key type* to `Authentication`
3. Set *Key provider* to `Environment`
4. Set *Environment variable* to `ANTHROPIC_API_KEY`
5. Save

**2c — Connect the key to the AI module**

Go to **Admin → Configuration → AI → Providers → Anthropic** and select the key you just created. Set it as the default provider for the *Chat* operation type.

### Step 3 — Create the intake Webform

1. Go to **Admin → Structure → Webforms → Add webform**
2. Add a *Textarea* field: machine name `service_request`, label *"Describe what you need help with"*
3. Add four **Hidden** fields with these machine names — these store the AI response on the submission:
   - `ministry`
   - `next_steps`
   - `confidence`
   - `needs_review`

### Step 4 — Build the custom module

Create `web/modules/custom/service_intake/` with the following structure:

```
service_intake/
├── service_intake.info.yml
├── service_intake.module
├── service_intake.routing.yml
├── src/
│   ├── Plugin/WebformHandler/IntakeClassifierHandler.php
│   └── Controller/IntakeResultController.php
└── templates/
    └── intake-result.html.twig
```

**`service_intake.info.yml`** — declares the module and its dependencies:

```yaml
name: Service Intake Classifier
type: module
core_version_requirement: ^11
dependencies:
  - drupal:webform
  - ai:ai
```

### Step 5 — Write the Webform Handler

`IntakeClassifierHandler.php` is a `WebformHandlerBase` plugin. Key implementation notes from development:

**AI module service and method chain:**
- The correct service name is `ai.provider` (not `plugin.manager.ai.provider`)
- `getDefaultProviderForOperationType('chat')` returns a config array `['provider_id' => '...', 'model_id' => '...']`, not a provider instance
- Use `createInstance($provider_id)` to get the provider, then pass `$model_id` as the second argument to `chat()`
- The response chain is: `->chat($input, $model_id)->getNormalized()->getText()`

**Prompt engineering — Claude returns markdown by default:**
Despite being instructed to return only JSON, Claude wraps responses in markdown code fences (` ```json ... ``` `). The system prompt must explicitly prohibit this, and the response should still be cleaned defensively:

```php
$system_prompt = <<<PROMPT
You are a classifier for Alberta government services. Given a plain-language service request,
respond with ONLY a raw JSON object — no markdown, no code fences, no explanation.
The JSON must have exactly these fields:
- ministry: the responsible Alberta ministry (string)
- next_steps: a plain-language summary of what the citizen should do next (string)
- confidence: your confidence in the classification, from 0.0 to 1.0 (float)
PROMPT;

// Strip markdown code fences defensively even after explicit prompt instructions.
$raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
$raw = preg_replace('/\s*```$/', '', $raw);
```

**Confidence threshold routing:**
```php
$flagged = ($result->confidence < $this->configuration['confidence_threshold']);
$webform_submission->setElementData('ministry', $result->ministry);
$webform_submission->setElementData('next_steps', $result->next_steps);
$webform_submission->setElementData('confidence', $result->confidence);
$webform_submission->setElementData('needs_review', $flagged);
$webform_submission->resave();
```

> `setElementData()` only persists values for fields that exist on the webform — this is why the hidden fields in Step 3 are required.

### Step 6 — Create the result page

`service_intake.routing.yml`:

```yaml
service_intake.result:
  path: '/intake/result/{sid}'
  defaults:
    _controller: '\Drupal\service_intake\Controller\IntakeResultController::result'
    _title: 'Your Service Request'
  requirements:
    _permission: 'access content'
    sid: \d+
```

`IntakeResultController.php` loads the submission by ID and passes the hidden field values to the `intake_result` theme hook. Register the hook in `service_intake.module`:

```php
function service_intake_theme(): array {
  return [
    'intake_result' => [
      'variables' => [
        'ministry'   => NULL,
        'next_steps' => NULL,
        'confidence' => NULL,
        'flagged'    => FALSE,
      ],
    ],
  ];
}
```

### Step 7 — Write the Twig template

`templates/intake-result.html.twig` renders the result page. Show ministry and next steps for auto-routed results; show a human-review message for flagged ones. Use `|t` on all user-facing strings for translation support.

### Step 8 — Configure the confirmation redirect

In the webform's **Settings → Confirmation** tab:
- Set *Confirmation type* to **Redirect to URL**
- Set *Redirect URL* to `/intake/result/[webform_submission:sid]`

Webform replaces `[webform_submission:sid]` with the actual submission ID, sending the user directly to their result page.

### Step 9 — Enable and test

```bash
ddev exec drush en service_intake -y
ddev exec drush cr
```

Visit `https://drupal-ai.ddev.site/webform/service_request`, submit a request, and verify you are redirected to the result page.

### Debugging AI API calls

**Check the Drupal watchdog logs first.** The handler logs all API failures to the `service_intake` channel:

```bash
ddev exec drush watchdog:show --count=20 --severity=Error
```

**Symptom: form submits but result fields are all empty**

The handler caught an exception silently. Check the logs for a `Claude API call failed:` entry. Common causes:

- `Syntax error` — Claude returned markdown-wrapped JSON (` ```json ... ``` `) instead of raw JSON. The handler strips code fences defensively, but if the prompt changes and wrapping reappears this is the first thing to check. Log `$raw` before `json_decode` to inspect the raw response.
- `No AI provider available` — the default chat provider isn't configured. Go to **Admin → Configuration → AI → Providers** and verify Anthropic is set as the default for the *Chat* operation type.

**Symptom: `ServiceNotFoundException` for `plugin.manager.ai.provider`**

Wrong service name. The correct service is `ai.provider`.

**Symptom: result fields exist on the submission but are empty after the handler runs**

`setElementData()` silently discards values for fields that don't exist on the webform. Make sure all four hidden fields (`ministry`, `next_steps`, `confidence`, `needs_review`) are defined on the webform in the form builder.

**Inspecting the raw Claude response**

Add a temporary log line before `json_decode` to see exactly what Claude is returning:

```php
\Drupal::logger('service_intake')->debug('Raw response: @raw', ['@raw' => $raw]);
```

Then tail the logs:

```bash
ddev exec drush watchdog:show --count=5 --type=service_intake
```

Remove the debug log line before committing.

**Checking the API key is available**

```bash
ddev exec drush eval "echo getenv('ANTHROPIC_API_KEY');"
```

If this returns empty, the key isn't in the environment — check `.ddev/config.local.yaml` and run `ddev restart`.

### Unit testing this module

The core classification logic lives in `IntakeService`, which has no Drupal dependencies whatsoever — no base classes, no service container, no `\Drupal::*` calls. This makes it straightforwardly testable with plain PHPUnit and no Drupal bootstrap.

**Running the tests:**

```bash
vendor/bin/phpunit --testdox
```

The test suite covers `parseResponse()` (valid JSON, markdown fence stripping, missing fields, malformed input) and `applyThreshold()` (boundary conditions, error payloads).

**Why no bootstrap is needed.**
The `Drupal\service_intake\` namespace is registered directly in `composer.json` under `autoload.psr-4`, so it is part of the normal Composer autoloader. PHPUnit finds the class without any custom bootstrap file.

**Why the handler is not unit tested.**
`IntakeClassifierHandler` is intentionally excluded from the unit test suite because it is pure Drupal wiring — it extends `WebformHandlerBase`, depends on `AiProviderPluginManager` (which is `final` and cannot be mocked), and uses the Drupal service container. Testing it in isolation requires a full Drupal bootstrap or significant boilerplate. The appropriate tests for the handler are Drupal kernel tests, run inside DDEV:

```bash
ddev exec vendor/bin/phpunit --group service_intake
```

**The design decision behind this split.**
Keeping all business logic in `IntakeService` and all Drupal wiring in the handler is what makes the unit tests possible at all. If the AI call, JSON parsing, and threshold logic had stayed inside the handler (as is common in simpler Drupal modules), none of it would be unit-testable without a full Drupal environment.

### Exporting changes

After any content or config change you want to preserve:

```bash
ddev export-db --file=db-init/init.sql.gz
git add db-init/init.sql.gz
git commit -m "describe the change"
```

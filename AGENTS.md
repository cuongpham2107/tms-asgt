<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>

<!-- CODEGRAPH_START -->

## CodeGraph

This project has a CodeGraph MCP server (`codegraph_*` tools) configured. CodeGraph is a tree-sitter-parsed knowledge graph of every symbol, edge, and file. Reads are sub-millisecond and return structural information grep cannot.

### When to prefer codegraph over native search

Use codegraph for **structural** questions — what calls what, what would break, where is X defined, what is X's signature. Use native grep/read only for **literal text** queries (string contents, comments, log messages) or after you already have a specific file open.

| Question                                      | Tool                |
| --------------------------------------------- | ------------------- |
| "Where is X defined?" / "Find symbol named X" | `codegraph_search`  |
| "What calls function Y?"                      | `codegraph_callers` |
| "What does Y call?"                           | `codegraph_callees` |
| "What would break if I changed Z?"            | `codegraph_impact`  |
| "Show me Y's signature / source / docstring"  | `codegraph_node`    |
| "Give me focused context for a task/area"     | `codegraph_context` |
| "See several related symbols' source at once" | `codegraph_explore` |
| "What files exist under path/"                | `codegraph_files`   |
| "Is the index healthy?"                       | `codegraph_status`  |

### Rules of thumb

- **Answer directly — don't delegate exploration.** For "how does X work" / architecture / trace questions, answer with 2-3 codegraph calls: `codegraph_context` first, then ONE `codegraph_explore` for the source of the symbols it surfaces. Codegraph IS the pre-built index, so spawning a separate file-reading sub-task/agent — or running a grep + read loop — repeats work codegraph already did and costs more for the same answer.
- **Trust codegraph results.** They come from a full AST parse. Do NOT re-verify them with grep — that's slower, less accurate, and wastes context.
- **Don't grep first** when looking up a symbol by name. `codegraph_search` is faster and returns kind + location + signature in one call.
- **Don't chain `codegraph_search` + `codegraph_node`** when you just want context — `codegraph_context` is one call.
- **Don't loop `codegraph_node` over many symbols** — one `codegraph_explore` call returns several symbols' source grouped in a single capped call, while each separate node/Read call re-reads the whole context and costs far more.
- **Index lag**: the file watcher debounces ~500ms behind writes; don't re-query immediately after editing a file in the same turn.

### If `.codegraph/` doesn't exist

The MCP server returns "not initialized." Ask the user: _"I notice this project doesn't have CodeGraph initialized. Want me to run `codegraph init -i` to build the index?"_

<!-- CODEGRAPH_END -->

<!-- FILAMENT_LEAFLET_START -->

## Filament Leaflet

This project uses `eduardoribeirodev/filament-leaflet` for interactive maps. A domain skill is available at `.agents/skills/filament-leaflet/SKILL.md` — activate it when working with maps and geospatial features.

### Quick Reference

| Component        | Namespace                                                     | Use Case                     |
| ---------------- | ------------------------------------------------------------- | ---------------------------- |
| `MapWidget`      | `EduardoRibeiroDev\FilamentLeaflet\Widgets\MapWidget`         | Dashboard map widget         |
| `MapPicker`      | `EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker`          | Form field for coord picking |
| `GeoSearchInput` | `EduardoRibeiroDev\FilamentLeaflet\Fields\GeoSearchInput`     | Geocoding search in forms    |
| `MapColumn`      | `EduardoRibeiroDev\FilamentLeaflet\Tables\MapColumn`          | Map display in tables        |
| `MapEntry`       | `EduardoRibeiroDev\FilamentLeaflet\Infolists\MapEntry`        | Read-only map in infolists   |
| `Marker`         | `EduardoRibeiroDev\FilamentLeaflet\Layers\Marker`             | Map markers                  |
| `Circle`         | `EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Circle`      | Circle shapes                |
| `MarkerCluster`  | `EduardoRibeiroDev\FilamentLeaflet\LayerGroups\MarkerCluster` | Clustered markers            |

### Key Rules

- **Coordinate DTO**: Model coordinates column must cast to `EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate`
- **Static maps**: Use `->static()` to disable all interactions (dragging + zooming)
- **CRUD integration**: Set `$markerModel` and optional `$markerResource` on MapWidget for create/edit/delete
- **Performance**: Use `MarkerCluster::fromModel()` for datasets > 50 markers
- **Tile layers**: Can switch between OpenStreetMap, GoogleSatellite, Mapbox, and custom URLs
- **Publish assets**: Run `php artisan vendor:publish --tag=filament-leaflet` after install
    <!-- FILAMENT_LEAFLET_END -->

<!-- AGENT_SKILLS_START -->

## OpenCode Integration — Agent Skills

This project uses [agent-skills](https://github.com/addyosmani/agent-skills) for structured engineering workflows.

### Core Rules

- If a task matches a skill, you MUST invoke it using the `skill` tool
- Skills are located in `.agents/skills/<skill-name>/SKILL.md` (project-specific) or `skills/<skill-name>/SKILL.md` (global)
- Never implement directly if a skill applies
- Always follow the skill instructions exactly

### Intent → Skill Mapping

| Intent                              | Skill                                                                                |
| ----------------------------------- | ------------------------------------------------------------------------------------ |
| Feature / new functionality         | `spec-driven-development` → `incremental-implementation` + `test-driven-development` |
| Planning / breakdown                | `planning-and-task-breakdown`                                                        |
| Bug / failure / unexpected behavior | `debugging-and-error-recovery`                                                       |
| Code review                         | `code-review-and-quality`                                                            |
| Refactoring / simplification        | `code-simplification`                                                                |
| API or interface design             | `api-and-interface-design`                                                           |
| UI work                             | `frontend-ui-engineering`                                                            |
| Security                            | `security-and-hardening`                                                             |
| Performance                         | `performance-optimization`                                                           |
| Shipping                            | `shipping-and-launch`                                                                |
| Documentation / ADRs                | `documentation-and-adrs`                                                             |
| CI/CD                               | `ci-cd-and-automation`                                                               |

### Lifecycle Mapping

- DEFINE → `spec-driven-development`
- PLAN → `planning-and-task-breakdown`
- BUILD → `incremental-implementation` + `test-driven-development`
- VERIFY → `debugging-and-error-recovery`
- REVIEW → `code-review-and-quality`
- SHIP → `shipping-and-launch`

### Execution Model

For every request:

1. Determine if any skill applies (even 1% chance)
2. Invoke the appropriate skill using the `skill` tool
3. Follow the skill workflow strictly
4. Only proceed to implementation after required steps (spec, plan, etc.) are complete

### Anti-Rationalization

The following thoughts are incorrect:

- "This is too small for a skill"
- "I can just quickly implement this"
- "I'll gather context first"

Correct behavior: always check for and use skills first.

**Note**: Project-specific skills (filament-leaflet, laravel-best-practices, pest-testing, tailwindcss-development) take priority over generic agent-skills.

<!-- AGENT_SKILLS_END -->

<!-- REPO_SPECIFIC_START -->

## Repo-specific

### Architecture

- **Filament v5 pattern**: Every resource follows `app/Filament/Resources/{Name}/` with `{Name}Resource.php`, `Tables/{Name}Table.php` (extends `BaseTable`), `Schemas/{Name}Form.php`, `Pages/`, and optionally `Actions/`, `Widgets/`.
  - `BaseTable::applyDefaults()` provides default bulk/record actions — call it BEFORE adding columns.
  - `BaseResource` auto-handles soft-delete route binding via `getRecordRouteBindingEloquentQuery()`.
- **Non-resource Filament code**: `app/Filament/Pages/` for non-resource pages, `app/Filament/Widgets/` for dashboard widgets.
- **Custom form components** at `app/Filament/Forms/Components/`: `VehiclePicker`, `PillFilter`, `MapboxLocationPicker`, `CardPicker` — check here before building new ones.
- **Trip checkpoint system** at `app/Services/Trip/` — uses handler pattern: `TripCheckpointService` → `CheckpointFactory` + individual `*Handler` classes (marker interface `CheckpointHandlerInterface`, no enforced signature – each handler receives only the params it needs).
- **Authorization**: Filament Shield auto-generates policies in `app/Policies/`. New resources need `php artisan shield:generate --all --ignore-config-changes` to create policies.
- **API**: Sanctum-based, all driver endpoints behind `auth:sanctum` + `EnsureRoleVehicle` middleware (checks `driver` role). Routes in `routes/api.php`, prefix `driver/`.
- **Observers**: `OrderObserver` logs changes to tracked fields into `OrderEditLog` on update.
- **Mapbox** loaded via CDN in `AppPanelProvider` (also `mapbox-gl` npm package for Filament leaflet maps).

### DB & Migrations

- **SQLite** by default (both local and test). Production may differ — verify before writing raw SQL or platform-specific migrations.
- **ENUM columns** (`$table->enum()`): In SQLite these become CHECK constraints. Adding new values requires a migration with `->change()`. Tests use `:memory:`, so all migrations run fresh on each test run.

### Enum → query pitfall

When updating via **Eloquent relations** (`$trip->orders()->update([...])`), enum values must use `->value`:
```php
// CORRECT on relations:
$trip->orders()->where('status', OrderStatus::Sent->value)
    ->update(['status' => OrderStatus::InTransit->value]);

// OK on model queries (automatic cast):
Order::where('id', $id)->update(['status' => OrderStatus::Completed]);
```

### Commands

```bash
# Full dev stack (serve + queue + logs + vite)
composer run dev

# Test
php artisan test --compact
php artisan test --compact --filter=TestName

# Format (always before finalizing changes)
vendor/bin/pint --format agent

# Migration for new filament resource
php artisan make:filament-resource {name} --soft-deletes --view --generate --no-interaction
# Then generate shield policies:
php artisan shield:generate --all --ignore-config-changes

# Create Pest test
php artisan make:test --pest SomeFeatureTest
```

### Tests

- Pest v4, `uses(RefreshDatabase::class)` per test file (not global).
- Common pattern: `beforeEach()` sets up models via `Model::create()`, then `Sanctum::actingAs($driver)` for API calls.
- Key test files: `TripCheckpointTest` (checkpoint flow), `OrderFullFlowTest` (end-to-end with driver swaps), `TripHistoryTest` (API history endpoint), `OrderFlowHHHKTest` (order status transitions).

### Other gotchas

- Filament v5 form builder uses `Filament\Schemas\Schema` (not `Filament\Forms` from v3). However, field components (e.g. `TextInput`, `Select`, `DateTimePicker`) still come from `Filament\Forms\Components` — both `Schemas` and `Forms` namespaces coexist.
- Use `#[Url]` for `activeStatusFilter` on List pages; call `$this->resetPage()` when the filter value changes (see `ListTrips`, `ListOrders`, `ListVehicles` for examples).
- The `opencode.json` at root enables `laravel-boost` MCP server and `codegraph` MCP server.
- `CONTEXT.md` holds domain terminology (Vietnamese). `database/SCHEMA_REFERENCE.md` documents table schemas.

<!-- REPO_SPECIFIC_END -->

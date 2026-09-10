# Laravel Lead Distribution

Scalable CRM lead distribution module built with **PHP 8.3+, Laravel 11+, and PostgreSQL**.

The module automatically assigns new CRM leads to active managers using a pluggable distribution strategy. The default strategy is **Least Loaded**, which takes existing open leads into account to keep the workload as balanced as possible.

## Features

* Automatic distribution of `NEW` leads
* Active manager filtering
* Workload-aware lead assignment
* Extensible distribution strategy architecture
* Transactional lead assignment
* Assignment history
* Domain events
* Queue-based notifications
* PostgreSQL
* Feature and unit tests
* Raw SQL analytics query without Eloquent ORM
* Docker-based development environment

---

## Requirements

* PHP 8.3+
* Laravel 11+
* PostgreSQL 15+
* Docker / Docker Compose

Redis is optional and can be used for queue processing or distributed locking in a production environment.

---

## Getting Started

### Clone the repository

```bash
git clone https://github.com/YOUR_USERNAME/laravel-lead-distribution.git

cd laravel-lead-distribution
```

### Configure environment

```bash
cp .env.example .env
```

Configure the database connection if necessary.

The default Docker configuration uses PostgreSQL.

### Start the application

```bash
docker compose up -d
```

Install dependencies:

```bash
docker compose exec app composer install
```

Generate the application key:

```bash
docker compose exec app php artisan key:generate
```

Run migrations:

```bash
docker compose exec app php artisan migrate
```

### Run tests

```bash
docker compose exec app php artisan test
```

---

# API

## Distribute Leads

```http
POST /api/leads/distribute
```

The endpoint finds all unassigned leads with status `NEW` and distributes them between active managers.

### Example

```bash
curl -X POST http://localhost/api/leads/distribute \
  -H "Accept: application/json"
```

### Example response

```json
{
    "data": {
        "distributed": 9
    }
}
```

The endpoint is idempotent from the perspective of already processed leads: once a lead is assigned and its status changes from `NEW` to `IN_PROGRESS`, subsequent calls will not process it again.

---

# Domain Model

## Managers

| Field     | Type    | Description                               |
| --------- | ------- | ----------------------------------------- |
| id        | bigint  | Manager identifier                        |
| name      | string  | Manager name                              |
| is_active | boolean | Whether the manager can receive new leads |

## Leads

| Field      | Type            | Description      |
| ---------- | --------------- | ---------------- |
| id         | bigint          | Lead identifier  |
| manager_id | bigint nullable | Assigned manager |
| status     | enum            | Lead status      |
| created_at | timestamp       | Creation time    |
| updated_at | timestamp       | Last update      |

### Lead statuses

```text
NEW
IN_PROGRESS
DONE
CANCELED
```

## Lead Assignment History

Every assignment is recorded separately.

This provides an audit trail and allows the system to answer questions such as:

* who assigned the lead;
* when the assignment happened;
* which manager received the lead;
* how the lead changed over time.

---

# Architecture

The implementation separates HTTP, application, domain and infrastructure concerns.

The main flow is:

```text
HTTP Request
     |
     v
Form Request
     |
     v
Controller
     |
     v
Lead Distribution Service
     |
     +----------------------+
     |                      |
     v                      v
Distribution Strategy    Repository / DB
     |                      |
     v                      v
Manager Selection       Lead Assignment
                            |
                            v
                     Assignment History
                            |
                            v
                       Domain Event
                            |
                            v
                         Listener
                            |
                            v
                       Queue Job
```

The controller is intentionally thin.

Business rules are implemented in the application/service layer rather than in the controller.

---

# Distribution Strategy

The distribution logic is isolated behind a strategy interface.

Conceptually:

```php
interface LeadDistributionStrategy
{
    public function distribute(
        Collection $leads,
        Collection $managers
    ): array;
}
```

The application service does not need to know which algorithm is being used.

For example:

```text
LeadDistributionService
        |
        v
LeadDistributionStrategy
        |
        +-- LeastLoadedStrategy
        +-- RoundRobinStrategy
        +-- RandomStrategy
        +-- WeightedStrategy
```

This makes it possible to introduce another algorithm without modifying the controller or the main business workflow.

---

# Default Algorithm: Least Loaded

The default algorithm is **Least Loaded**.

The current workload of a manager is calculated using open leads:

```text
NEW + IN_PROGRESS
```

Inactive managers are excluded before distribution begins.

### Example

Current workload:

| Manager | Open Leads |
| ------- | ---------: |
| A       |         12 |
| B       |          4 |
| C       |          8 |

There are 9 new leads.

The algorithm always assigns the next lead to the manager with the lowest current workload and updates the in-memory workload after each assignment.

Conceptually:

```text
Initial:

A = 12
B = 4
C = 8

Lead #1 -> B
A = 12
B = 5
C = 8

Lead #2 -> B
A = 12
B = 6
C = 8

Lead #3 -> B
A = 12
B = 7
C = 8

Lead #4 -> B
A = 12
B = 8
C = 8

Lead #5 -> B/C
...
```

The final distribution minimizes the difference between manager workloads as far as the discrete assignment allows.

The important property is that **existing workload is considered**, rather than simply assigning an equal number of new leads to each manager.

---

# Why Least Loaded?

A simple round-robin algorithm is insufficient for this requirement.

For example:

```text
A = 12
B = 4
C = 8
```

A round-robin implementation may assign the same number of new leads to every manager, while A remains significantly more loaded than B.

Least Loaded instead considers the actual state of the system at assignment time.

Round Robin can still be implemented later because the distribution mechanism is abstracted behind a strategy interface.

---

# Concurrency and Data Consistency

Lead distribution changes multiple pieces of state:

1. Lead assignment
2. Lead status
3. Assignment history
4. Domain event / queue processing

The assignment operation is executed within a database transaction.

The important invariant is:

```text
NEW lead
   |
   v
manager assigned
   |
   v
status = IN_PROGRESS
   |
   v
assignment history created
```

These changes must not be partially persisted.

Database constraints and transactional updates are used to prevent inconsistent state.

For concurrent requests, row-level locking / appropriate transaction isolation can be used around the leads being distributed.

A production implementation can additionally use a distributed lock when multiple application instances may execute the distribution command concurrently.

Redis is a suitable option for this purpose.

---

# Idempotency

The endpoint must not process the same lead twice.

The implementation relies on the state transition:

```text
NEW -> IN_PROGRESS
```

Only `NEW` leads are eligible for distribution.

After successful assignment:

```text
manager_id != NULL
status = IN_PROGRESS
```

Therefore a subsequent request will not select that lead again.

The database transaction ensures that assignment and status transition happen atomically.

---

# Events

After a lead is successfully assigned, a domain event is generated.

Conceptually:

```php
LeadAssigned
```

The event contains information required by consumers, such as:

* lead ID;
* manager ID;
* assignment timestamp.

Events keep the core assignment workflow decoupled from secondary operations.

For example, future consumers could react to the same event to:

* update analytics;
* send notifications;
* write audit logs;
* integrate with external CRM systems.

---

# Queues

Notifications and other non-critical side effects are executed asynchronously.

The flow is:

```text
Lead assigned
      |
      v
LeadAssigned event
      |
      v
Listener
      |
      v
Queue Job
      |
      v
Notification / external operation
```

This prevents slow external operations from blocking the lead distribution request.

The queue implementation can use the database queue for local development and Redis in production.

---

# Database Indexes

The most important query is retrieving new leads:

```sql
SELECT *
FROM leads
WHERE status = 'NEW';
```

A suitable index is:

```sql
CREATE INDEX leads_status_idx
ON leads (status);
```

For this workload, a PostgreSQL partial index can be more efficient:

```sql
CREATE INDEX leads_new_idx
ON leads (id)
WHERE status = 'NEW';
```

For workload calculations:

```sql
SELECT manager_id, COUNT(*)
FROM leads
WHERE status IN ('NEW', 'IN_PROGRESS')
GROUP BY manager_id;
```

An index such as:

```sql
CREATE INDEX leads_manager_status_idx
ON leads (manager_id, status);
```

can support manager workload queries.

The exact indexing strategy should be validated using `EXPLAIN (ANALYZE, BUFFERS)` against production-like data volumes.

Indexes should not be added blindly because every additional index increases storage requirements and write overhead.

---

# Analytics SQL

The project also contains a raw PostgreSQL query that does not use Eloquent ORM.

It returns:

* manager;
* number of open leads;
* average time a lead remains in progress;
* number of completed leads during the last 30 days.

Example query:

```sql
SELECT
    m.id,
    m.name,

    COUNT(*) FILTER (
        WHERE l.status IN ('NEW', 'IN_PROGRESS')
    ) AS open_leads,

    AVG(
        CASE
            WHEN l.status = 'IN_PROGRESS'
            THEN EXTRACT(
                EPOCH FROM (COALESCE(l.updated_at, NOW()) - l.created_at)
            )
        END
    ) AS average_time_in_progress_seconds,

    COUNT(*) FILTER (
        WHERE l.status = 'DONE'
          AND l.updated_at >= NOW() - INTERVAL '30 days'
    ) AS completed_last_30_days

FROM managers m
LEFT JOIN leads l
    ON l.manager_id = m.id

GROUP BY m.id, m.name
ORDER BY m.id;
```

The exact definition of "time in progress" should be based on the business requirement.

For accurate historical duration calculations, an assignment/status history table is preferable to deriving duration only from `created_at` and `updated_at`.

---

# Testing

The project contains both feature and unit-level tests.

Important scenarios include:

### Distribution

* distributes `NEW` leads;
* ignores inactive managers;
* considers existing `NEW` leads;
* considers existing `IN_PROGRESS` leads;
* balances the resulting workload;
* changes assigned leads to `IN_PROGRESS`;
* stores assignment history.

### Idempotency

* already assigned leads are not processed again;
* already `IN_PROGRESS` leads are not redistributed;
* repeated endpoint calls do not duplicate assignment history.

### Edge cases

* no active managers;
* no new leads;
* one manager;
* more managers than leads;
* many leads and few managers.

### Architecture

Distribution strategies can be tested independently from the HTTP layer.

This allows the balancing algorithm to be verified without bootstrapping the entire application.

---

# Example Test Scenario

Given:

```text
Managers:

A = 12 open leads
B = 4 open leads
C = 8 open leads

New leads:

9
```

The test verifies that:

1. all 9 leads are assigned;
2. all assigned leads become `IN_PROGRESS`;
3. no inactive manager receives a lead;
4. assignment history contains 9 records;
5. the resulting workloads are as balanced as possible;
6. a second distribution request does not assign the same leads again.

---

# Technical Decisions

## Laravel Service Layer

The controller only handles HTTP concerns.

The actual distribution workflow belongs to an application service.

This makes the business logic reusable from:

* HTTP controllers;
* console commands;
* scheduled tasks;
* future internal APIs.

## Strategy Pattern

The Strategy pattern was chosen because distribution algorithms are expected to evolve.

Adding:

```text
Round Robin
Random
Weighted
Least Loaded
```

should not require modifications to the controller or assignment workflow.

Only a new strategy implementation and configuration/selection mechanism are required.

## PostgreSQL

PostgreSQL was selected because the task requires relational consistency and benefits from:

* transactions;
* row-level locking;
* partial indexes;
* aggregate queries;
* `FILTER`;
* interval/date operations;
* strong SQL capabilities.

## Events + Queues

Events separate the core business operation from side effects.

Queues prevent notifications and external integrations from increasing API response time.

## ORM + Raw SQL

Eloquent is used for normal application persistence because it integrates well with Laravel models, relationships and testing.

Raw SQL is intentionally used for the analytics requirement because the task explicitly requires an SQL implementation without ORM and because complex analytical queries are often clearer and easier to optimize directly in SQL.

---

# Trade-offs

## Simplicity vs. Distributed Locking

For a single application instance, database transactions and row locks are sufficient.

For multiple application instances processing the same distribution operation concurrently, an additional distributed lock can be introduced.

Adding Redis locking immediately would increase operational complexity, so it should be introduced when horizontal scaling requires it.

## Bulk Processing vs. Per-Lead Events

Bulk database operations are faster for very large lead volumes.

However, every assigned lead has independent side effects:

* history;
* event;
* notification.

Therefore the implementation favors explicit per-lead assignment semantics over a single bulk update.

For very large volumes, the process can be optimized with chunking and bulk inserts while preserving the same domain boundaries.

## Exact Historical Duration

Using only `created_at` and `updated_at` is not sufficient to reconstruct every historical status transition.

For production-grade reporting, a status history table should be used:

```text
lead_status_history
-------------------
id
lead_id
from_status
to_status
created_at
```

This provides an accurate audit trail and allows exact calculation of time spent in each state.

---

# Scalability Considerations

Potential bottlenecks when the system grows include:

### Large number of NEW leads

Loading all new leads into memory does not scale indefinitely.

Possible improvement:

```text
chunkById()
```

or cursor-based processing.

### Large number of managers

Manager workload calculation should be performed using aggregate SQL rather than counting leads individually for every manager.

### Concurrent distribution workers

Multiple workers can compete for the same `NEW` leads.

Possible solutions:

* database row-level locking;
* `SELECT ... FOR UPDATE SKIP LOCKED`;
* distributed locks;
* partitioning work between workers.

### Queue throughput

Notification jobs should be processed independently from the API request.

Redis-backed queues and multiple workers can increase throughput.

### Analytics

Analytics queries should be benchmarked with:

```sql
EXPLAIN (ANALYZE, BUFFERS)
```

For very large datasets, reporting can be moved to:

* materialized views;
* pre-aggregated tables;
* dedicated analytics infrastructure.

---

# Possible Future Extensions

The current architecture allows adding:

```text
LeastLoadedStrategy
RoundRobinStrategy
WeightedStrategy
RandomStrategy
```

without changing the core assignment workflow.

Other possible extensions:

* manager capacity limits;
* manager specialization;
* working schedules;
* geographic distribution;
* priority-based leads;
* weighted manager capacity;
* team-based routing;
* fallback managers;
* distributed processing;
* retry policies;
* dead-letter queues;
* assignment SLA monitoring.

---

# Project Structure

A simplified structure:

```text
app/
├── Events/
│   └── LeadAssigned.php
│
├── Http/
│   ├── Controllers/
│   │   └── LeadDistributionController.php
│   └── Requests/
│       └── DistributeLeadsRequest.php
│
├── Jobs/
│   └── SendLeadAssignedNotification.php
│
├── Listeners/
│   └── SendLeadAssignedNotificationListener.php
│
├── Models/
│   ├── Lead.php
│   ├── LeadAssignmentHistory.php
│   └── Manager.php
│
└── Services/
    └── LeadDistribution/
        ├── LeadDistributionService.php
        ├── Contracts/
        │   └── LeadDistributionStrategy.php
        └── Strategies/
            ├── LeastLoadedStrategy.php
            ├── RoundRobinStrategy.php
            └── ...
```

The exact structure may evolve as the implementation grows, but the main principle remains the separation of:

```text
HTTP
 ↓
Application Service
 ↓
Distribution Strategy
 ↓
Persistence
 ↓
Events / Queue
```

---

# API Examples

A Postman collection is included in the repository.

Example request:

```http
POST /api/leads/distribute
Accept: application/json
```

No request body is required for the default implementation.

---

# Development Commands

Run the application:

```bash
docker compose up
```

Run migrations:

```bash
php artisan migrate
```

Run tests:

```bash
php artisan test
```

Run queue worker:

```bash
php artisan queue:work
```

Run code formatting:

```bash
vendor/bin/pint
```

Run static analysis:

```bash
vendor/bin/phpstan analyse
```

---

# License

This project was created as a technical assessment demonstrating Laravel application architecture, PostgreSQL usage, automated lead distribution, asynchronous processing, and testing.

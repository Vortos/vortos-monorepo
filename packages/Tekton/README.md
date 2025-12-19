Tekton Framework

The Architect's PHP Framework.

Tekton (Greek for "Builder") is an opinionated, architectural framework designed for building complex, scalable, and maintainable enterprise applications. It bridges the gap between rapid development and rigorous software engineering standards.

1. Philosophy

"Speed usually kills architecture. Tekton proves it doesn't have to."

While industry titans like Laravel and Symfony prioritize Rapid Application Development (RAD) by leveraging patterns like Active Record and MVC, they often inadvertently encourage tight coupling. As projects scale, this speed becomes technical debt, making adherence to Clean Architecture and Domain-Driven Design (DDD) an uphill battle.

Our Core Mission: To democratize advanced architectural patterns. Tekton provides a pre-wired, production-ready infrastructure that enforces strict boundaries and separation of concerns by default.

Key Differentiators:

    Enforced Clean Architecture: The Domain is the immutable core, completely decoupled from the Infrastructure and UI layers.

    Zero-Config Rigor: We believe developers shouldn't have to spend weeks wiring buses or configuring DI containers. Tekton bakes these patterns in, allowing you to focus immediately on solving complex Domain problems.

    Standing on Giants: Tekton is pragmatic. Under the hood, we leverage battle-tested Symfony Components (Dependency Injection, Messenger, Routing) to ensure stability and security, assembling them into a stricter, DDD-compliant blueprint.

2. Architecture & Patterns

Tekton is built on four non-negotiable pillars:

    Domain-Driven Design (DDD): The codebase structure mirrors the business language (Ubiquitous Language), not the database schema.

    CQRS (Command Query Responsibility Segregation):

        Writes (Commands): Optimized for transactional integrity using PostgreSQL.

        Reads (Queries): Optimized for high-performance retrieval using MongoDB (Projections) and Redis (Caching).

    Event-Driven Architecture: Side effects are decoupled via Domain Events.

    Hybrid Consistency Model:

        State Persistence: We persist the current state of entities to ensure simplicity and transactional safety (avoiding the operational complexity of full Event Sourcing).

        Eventual Consistency: Read models and external notifications are updated asynchronously via the Event Bus.

3. The Tech Stack

Tekton runs on a modern, containerized stack designed for performance and observability.

    Runtime: PHP 8.4 (FPM-Alpine)

    Web Server: Nginx (Alpine)

    Write Store: PostgreSQL 16 (Strict Relational)

    Read Store: MongoDB 8.0 (Document Store)

    Cache & Transport: Redis (Alpine)

    Infrastructure: Docker & Docker Compose (Microservices Topology)

4. Directory Structure

Tekton adheres to the Onion Architecture (also known as Hexagonal/Ports & Adapters).
Plaintext

/
├── config/ # Framework wiring (Routes, Services, Packages)
├── docker/ # Infrastructure definitions (Nginx, PHP, Postgres configs)
├── packages/ # Core Framework components (The "Tekton" vendor code)
├── public/ # Entry point (index.php)
└── src/ # YOUR APPLICATION CODE
├── Domain/ # Pure Business Logic (Entities, Value Objects, Repository Interfaces)
├── Application/ # Use Cases (Command Handlers, Query Handlers)
├── Infrastructure/ # Implementations (Doctrine, Mongo Clients, API Clients)
└── UI/ # Entry Points (Controllers, CLI Commands)

5. Getting Started

Follow these steps to spin up the entire microservices infrastructure.
Prerequisites

    Docker & Docker Compose

    Git

Installation

    Clone the Repository
    Bash

git clone https://github.com/fortizan/tekton.git
cd tekton

Configure Environment Copy the example configuration to a live environment file.
Bash

cp .env.example .env

Ignite the Infrastructure This builds the PHP 8.4 and Nginx images and starts the database triad (Postgres, Mongo, Redis).
Bash

docker compose up -d --build

Install Dependencies Run Composer inside the container to ensure platform compatibility.
Bash

docker compose exec backend composer install

Verify Status Ensure all 5 services (Backend, Nginx, Write_DB, Read_DB, Redis) are running.
Bash

    docker compose ps

Development Workflow

    Access the API: http://localhost

    Database Access (Write): Connect to localhost:5432 (User: postgres / Pass: see .env)

    Database Access (Read): Connect to localhost:27017

    Check Logs:
    Bash

    docker compose logs -f backend

6. Deployment Strategy

Tekton is designed as a Headless API.

    Backend (The Core): Hosted on VPS/Cloud (e.g., Oracle Cloud, AWS) via Docker Compose for stateful persistence and raw performance.

    Frontend (The UI): Recommended hosting on Edge Networks (e.g., Vercel, Netlify) consuming the Tekton API.

    Documentation: Static generation hosted on Vercel.

License

This framework is open-sourced software licensed under the MIT license.

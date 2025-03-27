# SwallowPHP Framework Analysis

This document provides an analysis of the SwallowPHP framework based on examining its core components in the `src` directory.

## Overall Framework Summary

The SwallowPHP framework implements a basic Model-View-Controller (MVC) pattern:

1.  **Request Entry:** `App::run()` initializes the environment, creates a `Request` object, and potentially handles Gzip, SSL redirection, and sessions.
2.  **Routing:** The `Request` is passed to `Router::dispatch()`, which matches the URI and HTTP method against registered `Route` definitions using regular expressions.
3.  **Middleware:** The matched `Route` executes its associated middleware pipeline (if any) in an "onion" pattern.
4.  **Controller/Action:** The innermost part of the pipeline (or the route itself if no middleware) executes the defined action, which can be a Closure or a method on a Controller class (e.g., `UserController@show`). Controllers are instantiated, and the method is called, potentially with the `Request` object injected.
5.  **Data Interaction (Optional):** Controllers often interact with `Model` classes. The `Model` class provides an Active Record pattern, using the `Database` class's fluent query builder to interact with the database (primarily MySQL via PDO).
6.  **Response:** The action returns data (often an array, object, or HTML string). `App::outputResponse()` determines the desired format (JSON or HTML based on `Accept` header) and sends the response to the client.
7.  **Error Handling:** Exceptions throughout the process are caught by `App::run()` and handled by `ExceptionHandler::handle()`.

## Strengths

*   Clear separation of concerns following MVC principles.
*   Fluent and secure query builder (`Database`).
*   Basic Active Record implementation (`Model`) simplifying database interactions.
*   Support for middleware.
*   Named routes for easier URL generation.
*   Configurable caching backend (File/SQLite).
*   Environment-based configuration (`.env`).

## Potential Areas for Improvement

*   **View Layer:** Currently uses basic `print_r` for output. Integrating a dedicated templating engine (like Twig, Blade, or Plates) would significantly improve view creation and management.
*   **Dependency Injection:** DI seems limited. A dedicated DI container could manage class dependencies more effectively, making the code more testable and flexible.
*   **ORM Features:** The `Model` class is basic. Features like lazy loading for relationships, more advanced relationship types, and potentially more sophisticated event handling could be beneficial.
*   **Database State:** The `Database` class resets after every query, meaning instances aren't easily reused for slight query variations without rebuilding the chain. The `Model`'s heavy use of static methods returning `new static()` for query building is also somewhat unconventional.
*   **Testing:** No clear testing infrastructure is visible.
*   **Security:** While PDO prepared statements prevent SQL injection, other aspects like CSRF protection and comprehensive XSS filtering might need explicit implementation.

## Request Lifecycle Diagram

```mermaid
sequenceDiagram
    participant Client
    participant App.php
    participant Request.php
    participant Router.php
    participant Route.php
    participant Middleware
    participant Controller/Action
    participant Model.php
    participant Database.php
    participant Response.php

    Client->>App.php: HTTP Request
    App.php->>Request.php: createFromGlobals()
    Request.php-->>App.php: Request Object
    App.php->>Router.php: dispatch(Request)
    Router.php->>Route.php: Loop: match(Method, URI)?
    Route.php-->>Router.php: Matched Route / Exception
    Router.php->>Route.php: execute(Request)
    Route.php->>Middleware: handle(Request, next)
    Middleware->>Middleware: handle(Request, next)
    Middleware->>Route.php: executeAction(Request)
    Route.php->>Controller/Action: Instantiate & Call Method
    Controller/Action->>Model.php: (Optional) e.g., User::where(...)
    Model.php->>Database.php: (Optional) Build & Execute Query
    Database.php-->>Model.php: (Optional) Raw Data
    Model.php-->>Controller/Action: (Optional) Hydrated Model(s)
    Controller/Action-->>Route.php: Return Data/Response
    Route.php-->>Middleware: Return Data/Response
    Middleware-->>Middleware: Return Data/Response
    Middleware-->>Route.php: Return Data/Response
    Route.php-->>Router.php: Return Data/Response
    Router.php-->>App.php: Return Data/Response
    App.php->>Response.php: Create Response (JSON/HTML)
    Response.php-->>App.php: Response Object/String
    App.php->>Client: Send HTTP Response
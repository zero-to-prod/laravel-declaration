---
task: >-
  Write a context complete implementation plan for {component}.
steps:
  - text: Conform all implementations to the style guide
    details:
      - `style-guide.md`
  - text: Understand {component}
    details:
      - `docs/implementation/components/component-index.md`
  - text: Understand the relevant source code for {component}
    details:
      - `src/`
      - `tests/`
  - text: Understand the relevant documentation for {component}
    details:
      - `docs/repos/laravel/docs/`
      - `docs/repos/zero-to-prod/data-model/README.md`
      - `docs/repos/pestphp/docs/`
  - text: Understand the relevant vendor source code
    details:
      - vendor/laravel/
  - text: Decompose the problem into atoms
  - text: Justify each atom by backing and referencing its sources
goal: >-
  Implement a yml data structure and php implementation that maps 1-to-1 to
  the laravel API.
strategy:
  - Use dynamic dispatch to keep naming vertically consistent and code simple
  - The keys map to function names, the values map to the function signature
  - Use Attribute Oriented Programming (AOP) over of imperative programming
  - Use existing patterns in the codebase
deliverables:
  - A context complete Markdown file
  - All code examples are complete
  - All implementation details are referenced to the source code
  - No code is implemented
---
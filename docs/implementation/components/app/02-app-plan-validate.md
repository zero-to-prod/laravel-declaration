---
name: plan-validate
task: >-
  Iterate line by line through <plan> and validate the document.
steps:
  - text: Visit the source code
  - text: Visit the vendor source code
    details:
      - vendor/laravel/
      - vendor/laravel/
  - text: Compare the implementation details with the source of truth
  - text: Extract the diff and update <plan>
deliverables:
  - No gaps in implementation are found compared to the source of truth
  - Implementation is mapped 1-to-1 to the api
  - <plan> is updated
---
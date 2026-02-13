# Java Stub Client

This stub demonstrates the phase-2 Java desktop integration contract.

## Build

```bash
mvn -f java-stub-client/pom.xml package
```

## Available methods

- `requestToken(email, password)`
- `startFocusSession(token, lessonId, quizId, durationSeconds)`
- `pushFocusEvent(token, focusSessionId, type, severity, details)`
- `endFocusSession(token, focusSessionId, status)`

Use API contract in `docs/api/openapi.yaml`.

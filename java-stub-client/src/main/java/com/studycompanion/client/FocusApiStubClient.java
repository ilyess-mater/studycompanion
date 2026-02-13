package com.studycompanion.client;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class FocusApiStubClient {
    private final HttpClient httpClient = HttpClient.newHttpClient();
    private final String baseUrl;

    public FocusApiStubClient(String baseUrl) {
        this.baseUrl = baseUrl;
    }

    public String requestToken(String email, String password) throws IOException, InterruptedException {
        String body = String.format("{\"email\":\"%s\",\"password\":\"%s\"}", email, password);
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/v1/auth/token"))
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();

        return httpClient.send(request, HttpResponse.BodyHandlers.ofString()).body();
    }

    public String startFocusSession(String bearerToken, int lessonId, int quizId, int durationSeconds) throws IOException, InterruptedException {
        String body = String.format("{\"lessonId\":%d,\"quizId\":%d,\"durationSeconds\":%d}", lessonId, quizId, durationSeconds);
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/v1/focus-sessions"))
                .header("Authorization", "Bearer " + bearerToken)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();

        return httpClient.send(request, HttpResponse.BodyHandlers.ofString()).body();
    }

    public String pushFocusEvent(String bearerToken, int focusSessionId, String type, int severity, String details) throws IOException, InterruptedException {
        String body = String.format("{\"type\":\"%s\",\"severity\":%d,\"details\":\"%s\"}", type, severity, details.replace("\"", "'"));
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/v1/focus-sessions/" + focusSessionId + "/events"))
                .header("Authorization", "Bearer " + bearerToken)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();

        return httpClient.send(request, HttpResponse.BodyHandlers.ofString()).body();
    }

    public String endFocusSession(String bearerToken, int focusSessionId, String status) throws IOException, InterruptedException {
        String body = String.format("{\"status\":\"%s\"}", status);
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/v1/focus-sessions/" + focusSessionId + "/end"))
                .header("Authorization", "Bearer " + bearerToken)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();

        return httpClient.send(request, HttpResponse.BodyHandlers.ofString()).body();
    }

    public static void main(String[] args) {
        System.out.println("Use this stub to integrate Java desktop focus events with Symfony backend APIs.");
    }
}

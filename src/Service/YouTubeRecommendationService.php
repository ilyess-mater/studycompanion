<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YouTubeRecommendationService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $youtubeApiKey,
    ) {
    }

    public function hasProvider(): bool
    {
        return $this->youtubeApiKey !== '';
    }

    /**
     * @return list<array{title:string,url:string,channelName:string,score:float}>
     */
    public function recommend(string $query, int $limit = 5): array
    {
        if (!$this->hasProvider()) {
            return $this->fallback($query, $limit);
        }

        try {
            $response = $this->httpClient->request('GET', 'https://www.googleapis.com/youtube/v3/search', [
                'query' => [
                    'key' => $this->youtubeApiKey,
                    'part' => 'snippet',
                    'type' => 'video',
                    'maxResults' => $limit,
                    'q' => $query,
                    'safeSearch' => 'strict',
                ],
                'timeout' => 15,
            ]);

            $payload = $response->toArray(false);
            $items = $payload['items'] ?? [];
            $results = [];
            foreach ($items as $index => $item) {
                $videoId = (string) ($item['id']['videoId'] ?? '');
                if ($videoId === '') {
                    continue;
                }

                $results[] = [
                    'title' => trim((string) ($item['snippet']['title'] ?? 'Untitled lesson video')),
                    'url' => 'https://www.youtube.com/watch?v='.$videoId,
                    'channelName' => trim((string) ($item['snippet']['channelTitle'] ?? 'YouTube')),
                    'score' => max(0.1, 1 - ($index * 0.1)),
                ];
            }

            if ($results !== []) {
                return $results;
            }
        } catch (TransportException|\Throwable) {
            // Fallback below.
        }

        return $this->fallback($query, $limit);
    }

    /**
     * @return list<array{title:string,url:string,channelName:string,score:float}>
     */
    private function fallback(string $query, int $limit): array
    {
        $results = [];
        for ($i = 0; $i < $limit; ++$i) {
            $results[] = [
                'title' => sprintf('YouTube search result for %s (%d)', $query, $i + 1),
                'url' => 'https://www.youtube.com/results?search_query='.rawurlencode($query),
                'channelName' => 'YouTube Search',
                'score' => max(0.1, 1 - ($i * 0.1)),
            ];
        }

        return $results;
    }
}

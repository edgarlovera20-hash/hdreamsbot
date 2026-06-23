<?php

namespace App\Services;

class KnowledgeBaseService
{
    private AIClient $ai;

    public function __construct(private \mysqli $db)
    {
        $this->ai = new AIClient();
    }

    public function indexDocument(int $empresaId, int $documentId, string $title, string $text): void
    {
        $titleEsc = $this->db->real_escape_string($title);
        $textEsc = $this->db->real_escape_string($text);
        $this->db->query(
            "UPDATE knowledge_documents
             SET title = '$titleEsc',
                 text_content = '$textEsc',
                 status = 'indexed'
             WHERE id = $documentId AND empresa_id = $empresaId"
        );

        $this->db->query("DELETE FROM knowledge_chunks WHERE document_id = $documentId");
        $chunks = $this->chunkText($text);
        foreach ($chunks as $index => $chunk) {
            $chunkEsc = $this->db->real_escape_string($chunk);
            $tokens = max(1, (int) ceil(strlen($chunk) / 4));
            $this->db->query(
                "INSERT INTO knowledge_chunks (document_id, empresa_id, chunk_index, content, token_count)
                 VALUES ($documentId, $empresaId, " . (int) $index . ", '$chunkEsc', $tokens)"
            );
        }
    }

    public function ask(int $empresaId, string $question): array
    {
        $chunks = $this->searchChunks($empresaId, $question);
        $context = implode("\n\n", array_map(
            fn (array $chunk) => "[{$chunk['title']}] {$chunk['content']}",
            $chunks
        ));

        $prompt = <<<PROMPT
Responde en español usando solo este contexto interno de empresa.

PREGUNTA:
$question

CONTEXTO:
$context

Devuelve JSON válido con:
- answer
- confidence (0-100)
- sources (array con títulos)
PROMPT;

        $response = $this->ai->chatJson($prompt);
        if (!$response) {
            return [
                'answer' => $chunks ? 'Encontré contexto interno relevante, pero el modelo no respondió. Revisa las fuentes sugeridas.' : 'No encontré documentos internos relevantes para esa pregunta.',
                'confidence' => $chunks ? 45 : 10,
                'sources' => array_values(array_unique(array_column($chunks, 'title'))),
            ];
        }

        return [
            'answer' => (string) ($response['answer'] ?? ''),
            'confidence' => (int) ($response['confidence'] ?? 0),
            'sources' => is_array($response['sources'] ?? null) ? $response['sources'] : array_values(array_unique(array_column($chunks, 'title'))),
        ];
    }

    public function recentDocuments(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT id, title, source_filename, mime_type, status, created_at
             FROM knowledge_documents
             WHERE empresa_id = $empresaId
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }
        return $items;
    }

    private function searchChunks(int $empresaId, string $question): array
    {
        $terms = preg_split('/\s+/', mb_strtolower(trim($question))) ?: [];
        $terms = array_values(array_filter($terms, fn ($term) => mb_strlen($term) >= 4));
        $termClauses = [];
        foreach (array_slice($terms, 0, 6) as $term) {
            $termEsc = $this->db->real_escape_string($term);
            $termClauses[] = "LOWER(kc.content) LIKE '%$termEsc%'";
        }

        $sql = "SELECT kc.id, kc.content, kd.title
                FROM knowledge_chunks kc
                JOIN knowledge_documents kd ON kd.id = kc.document_id
                WHERE kc.empresa_id = $empresaId" .
                ($termClauses ? " AND (" . implode(' OR ', $termClauses) . ")" : '') . "
                ORDER BY kc.created_at DESC
                LIMIT 5";
        $result = $this->db->query($sql);
        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }
        return $items;
    }

    private function chunkText(string $text): array
    {
        $text = trim(preg_replace("/\r\n|\r/", "\n", $text));
        $parts = preg_split("/\n{2,}/", $text) ?: [];
        $chunks = [];
        $buffer = '';

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (mb_strlen($buffer . "\n\n" . $part) > 900 && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = $part;
            } else {
                $buffer = $buffer === '' ? $part : ($buffer . "\n\n" . $part);
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks ?: [mb_substr($text, 0, 900)];
    }
}

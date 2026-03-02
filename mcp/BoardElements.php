<?php

namespace Rhymix\Modules\Mcpserver\Mcp;

use Rhymix\Modules\Mcpserver\Models\MCPServerInterface;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

/**
 * BoardElements
 * 
 * MCP tools for retrieving document (post) and comment lists with pagination support.
 * 
 * @package Rhymix\Modules\Mcpserver\Mcp
 */
class BoardElements extends MCPServerInterface
{
    /**
     * Retrieves a paginated list of documents (posts) from a specific module.
     * 
     * @param int $module_srl Module SRL to retrieve documents from
     * @param int $page Page number (default: 1)
     * @param int $list_count Number of documents per page (default: 20)
     * @param int $page_count Number of page links for navigation (default: 10)
     * @param bool $include_content Whether to include document content (default: false)
     * @return array Document list with pagination info (current_page, max_page, total_count, documents)
     */
    #[McpTool(name: 'get_document_list')]
    public function getDocumentList(
        #[Schema(type: 'integer', minimum: 1)]
        int $module_srl,

        #[Schema(type: 'integer', minimum: 1)]
        int $page = 1,

        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $list_count = 20,

        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $page_count = 10,

        #[Schema(type: 'boolean')]
        bool $include_content = false
    ): array {
        $args = new \stdClass();
        $args->module_srl = $module_srl;
        $args->page = $page;
        $args->list_count = $list_count;
        $args->page_count = $page_count;

        $output = executeQueryArray('document.getDocumentList', $args);

        if (!$output->toBool()) {
            throw new \Exception('Cannot retrieve document list: ' . $output->getMessage());
        }

        $documents = [];
        if ($output->data) {
            foreach ($output->data as $document) {
                $doc = [
                    'document_srl' => $document->document_srl,
                    'title' => $document->title,
                    'nick_name' => $document->nick_name,
                    'comment_count' => $document->comment_count,
                    'readed_count' => $document->readed_count,
                    'voted_count' => $document->voted_count,
                    'regdate' => $document->regdate,
                    'last_update' => $document->last_update,
                    'status' => $document->status,
                    'category_srl' => $document->category_srl,
                ];
                if ($include_content) {
                    $doc['content'] = $document->content;
                }
                $documents[] = $doc;
            }
        }

        $totalPage = $output->total_page ?? 1;

        return [
            'current_page' => $page,
            'max_page' => (int)$totalPage,
            'total_count' => (int)($output->total_count ?? 0),
            'documents' => $documents,
        ];
    }

    /**
     * Retrieves the content (body) of a specific document (post).
     * 
     * @param int $document_srl Document SRL to retrieve content from
     * @return array Document details including title, content, author, and metadata
     */
    #[McpTool(name: 'get_document_content')]
    public function getDocumentContent(
        #[Schema(type: 'integer', minimum: 1)]
        int $document_srl
    ): array {
        $args = new \stdClass();
        $args->document_srl = $document_srl;

        $output = executeQuery('document.getDocument', $args);

        if (!$output->toBool()) {
            throw new \Exception('Cannot retrieve document: ' . $output->getMessage());
        }

        if (!$output->data) {
            throw new \Exception('Document not found: ' . $document_srl);
        }

        $document = $output->data;

        return [
            'document_srl' => $document->document_srl,
            'module_srl' => $document->module_srl,
            'title' => $document->title,
            'content' => $document->content,
            'nick_name' => $document->nick_name,
            'member_srl' => $document->member_srl,
            'comment_count' => $document->comment_count,
            'readed_count' => $document->readed_count,
            'voted_count' => $document->voted_count,
            'regdate' => $document->regdate,
            'last_update' => $document->last_update,
            'status' => $document->status,
            'category_srl' => $document->category_srl,
            'tags' => $document->tags ?? '',
        ];
    }

    /**
     * Retrieves multiple documents (posts) with full content by their document SRLs.
     * Returns up to 100 documents at once including their body content.
     * 
     * @param array $document_srls Array of document SRLs to retrieve (max 100)
     * @return array Document list with total_count and documents
     */
    #[McpTool(name: 'get_documents_by_srls')]
    public function getDocumentsBySrls(
        #[Schema(type: 'array', items: ['type' => 'integer'], minItems: 1, maxItems: 100)]
        array $document_srls
    ): array {
        if (empty($document_srls)) {
            throw new \Exception('document_srls must not be empty.');
        }

        if (count($document_srls) > 100) {
            throw new \Exception('document_srls must not exceed 100 items.');
        }

        $args = new \stdClass();
        $args->document_srls = array_map('intval', $document_srls);
        $args->list_count = count($document_srls);

        $output = executeQueryArray('document.getDocuments', $args);

        if (!$output->toBool()) {
            throw new \Exception('Cannot retrieve documents: ' . $output->getMessage());
        }

        $documents = [];
        if ($output->data) {
            foreach ($output->data as $document) {
                $documents[] = [
                    'document_srl' => $document->document_srl,
                    'module_srl' => $document->module_srl,
                    'title' => $document->title,
                    'content' => $document->content,
                    'nick_name' => $document->nick_name,
                    'member_srl' => $document->member_srl,
                    'comment_count' => $document->comment_count,
                    'readed_count' => $document->readed_count,
                    'voted_count' => $document->voted_count,
                    'regdate' => $document->regdate,
                    'last_update' => $document->last_update,
                    'status' => $document->status,
                    'category_srl' => $document->category_srl,
                    'tags' => $document->tags ?? '',
                ];
            }
        }

        return [
            'total_count' => count($documents),
            'documents' => $documents,
        ];
    }

    /**
     * Retrieves comments from multiple documents by their document SRLs.
     * Returns all comments from up to 100 documents at once including their content.
     * 
     * @param array $document_srls Array of document SRLs to retrieve comments from (max 100)
     * @return array Comment list with total_count and comments
     */
    #[McpTool(name: 'get_comments_by_srls')]
    public function getCommentsBySrls(
        #[Schema(type: 'array', items: ['type' => 'integer'], minItems: 1, maxItems: 100)]
        array $document_srls
    ): array {
        if (empty($document_srls)) {
            throw new \Exception('document_srls must not be empty.');
        }

        if (count($document_srls) > 100) {
            throw new \Exception('document_srls must not exceed 100 items.');
        }

        $args = new \stdClass();
        $args->document_srls = array_map('intval', $document_srls);
        $args->list_count = 10000;

        $output = executeQueryArray('mcpserver.getCommentsByDocumentSrls', $args);

        if (!$output->toBool()) {
            throw new \Exception('Cannot retrieve comments: ' . $output->getMessage());
        }

        $comments = [];
        if ($output->data) {
            foreach ($output->data as $comment) {
                $comments[] = [
                    'comment_srl' => $comment->comment_srl,
                    'document_srl' => $comment->document_srl,
                    'parent_srl' => $comment->parent_srl,
                    'depth' => $comment->depth ?? 0,
                    'content' => $comment->content,
                    'nick_name' => $comment->nick_name,
                    'member_srl' => $comment->member_srl,
                    'voted_count' => $comment->voted_count,
                    'blamed_count' => $comment->blamed_count,
                    'regdate' => $comment->regdate,
                    'last_update' => $comment->last_update,
                    'status' => $comment->status,
                    'is_secret' => $comment->is_secret,
                ];
            }
        }

        return [
            'total_count' => count($comments),
            'comments' => $comments,
        ];
    }

    /**
     * Retrieves a paginated list of comments for a specific document.
     * 
     * @param int $document_srl Document SRL to retrieve comments from
     * @param int $page Page number (default: 1)
     * @param int $list_count Number of comments per page (default: 20)
     * @param int $page_count Number of page links for navigation (default: 10)
     * @return array Comment list with pagination info (current_page, max_page, total_count, comments)
     */
    #[McpTool(name: 'get_comment_list')]
    public function getCommentList(
        #[Schema(type: 'integer', minimum: 1)]
        int $document_srl,

        #[Schema(type: 'integer', minimum: 1)]
        int $page = 1,

        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $list_count = 20,

        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $page_count = 10
    ): array {
        $args = new \stdClass();
        $args->document_srl = $document_srl;
        $args->page = $page;
        $args->list_count = $list_count;
        $args->page_count = $page_count;

        $output = executeQueryArray('comment.getCommentPageList', $args);

        if (!$output->toBool()) {
            throw new \Exception('Cannot retrieve comment list: ' . $output->getMessage());
        }

        $comments = [];
        if ($output->data) {
            foreach ($output->data as $comment) {
                $comments[] = [
                    'comment_srl' => $comment->comment_srl,
                    'parent_srl' => $comment->parent_srl,
                    'depth' => $comment->depth ?? 0,
                    'content' => $comment->content,
                    'nick_name' => $comment->nick_name,
                    'voted_count' => $comment->voted_count,
                    'blamed_count' => $comment->blamed_count,
                    'regdate' => $comment->regdate,
                    'last_update' => $comment->last_update,
                    'status' => $comment->status,
                    'is_secret' => $comment->is_secret,
                ];
            }
        }

        $totalPage = $output->total_page ?? 1;

        return [
            'current_page' => $page,
            'max_page' => (int)$totalPage,
            'total_count' => (int)($output->total_count ?? 0),
            'comments' => $comments,
        ];
    }
}

<?php

namespace Rhymix\Modules\Mcpserver\Mcp;

use Rhymix\Modules\Mcpserver\Models\MCPServerInterface;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

/**
 * RhymixBoardElements
 * 
 * This class provides MCP tools for retrieving document (post) and comment lists
 * from Rhymix CMS with pagination support.
 * 
 * @package Rhymix\Modules\Mcpserver\Mcp
 */
class RhymixBoardElements extends MCPServerInterface
{
    /**
     * Retrieves a paginated list of documents (posts) from a specific module.
     * 
     * @param int $module_srl The module serial number to retrieve documents from
     * @param int $page Page number for pagination (default: 1)
     * @param int $list_count Number of documents per page (default: 20)
     * @param int $page_count Number of page links to display (default: 10)
     * @return array Document list with pagination information
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
        int $page_count = 10
    ): array
    {
        $args = new \stdClass();
        $args->module_srl = $module_srl;
        $args->page = $page;
        $args->list_count = $list_count;
        $args->page_count = $page_count;
        $args->sort_index = 'list_order';
        $args->order_type = 'asc';

        $output = executeQueryArray('document.getDocumentList', $args);

        if (!$output->toBool()) {
            throw new \Exception('Cannot retrieve document list: ' . $output->getMessage());
        }

        $documents = array();
        if (!empty($output->data) && is_array($output->data)) {
            foreach ($output->data as $doc) {
                $documents[] = array(
                    'document_srl' => $doc->document_srl,
                    'title' => $doc->title,
                    'nick_name' => $doc->nick_name,
                    'content' => $doc->content,
                    'comment_count' => $doc->comment_count,
                    'readed_count' => $doc->readed_count,
                    'voted_count' => $doc->voted_count,
                    'regdate' => $doc->regdate,
                    'last_update' => $doc->last_update,
                    'status' => $doc->status,
                    'category_srl' => $doc->category_srl,
                );
            }
        }

        $pagination = array(
            'total_count' => isset($output->total_count) ? (int)$output->total_count : 0,
            'total_page' => isset($output->total_page) ? (int)$output->total_page : 0,
            'page' => $page,
            'list_count' => $list_count,
            'page_count' => $page_count,
        );

        return array(
            'pagination' => $pagination,
            'documents' => $documents,
        );
    }

    /**
     * Retrieves a paginated list of comments from a specific document or module.
     * 
     * @param int $document_srl The document serial number to retrieve comments from (0 for all)
     * @param int $module_srl The module serial number to retrieve comments from (0 for all, used when document_srl is 0)
     * @param int $page Page number for pagination (default: 1)
     * @param int $list_count Number of comments per page (default: 20)
     * @param int $page_count Number of page links to display (default: 10)
     * @return array Comment list with pagination information
     */
    #[McpTool(name: 'get_comment_list')]
    public function getCommentList(
        #[Schema(type: 'integer', minimum: 0)]
        int $document_srl = 0,

        #[Schema(type: 'integer', minimum: 0)]
        int $module_srl = 0,

        #[Schema(type: 'integer', minimum: 1)]
        int $page = 1,

        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $list_count = 20,

        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $page_count = 10
    ): array
    {
        $args = new \stdClass();
        $args->page = $page;
        $args->list_count = $list_count;
        $args->page_count = $page_count;

        if ($document_srl > 0) {
            $args->document_srl = $document_srl;
        }
        if ($module_srl > 0) {
            $args->s_module_srl = $module_srl;
        }

        $output = executeQueryArray('comment.getTotalCommentList', $args);

        if (!$output->toBool()) {
            throw new \Exception('Cannot retrieve comment list: ' . $output->getMessage());
        }

        $comments = array();
        if (!empty($output->data) && is_array($output->data)) {
            foreach ($output->data as $comment) {
                $comments[] = array(
                    'comment_srl' => $comment->comment_srl,
                    'document_srl' => $comment->document_srl,
                    'parent_srl' => $comment->parent_srl,
                    'nick_name' => $comment->nick_name,
                    'content' => $comment->content,
                    'voted_count' => $comment->voted_count,
                    'regdate' => $comment->regdate,
                    'last_update' => $comment->last_update,
                    'status' => $comment->status,
                    'document_title' => isset($comment->document_title) ? $comment->document_title : null,
                );
            }
        }

        $pagination = array(
            'total_count' => isset($output->total_count) ? (int)$output->total_count : 0,
            'total_page' => isset($output->total_page) ? (int)$output->total_page : 0,
            'page' => $page,
            'list_count' => $list_count,
            'page_count' => $page_count,
        );

        return array(
            'pagination' => $pagination,
            'comments' => $comments,
        );
    }
}

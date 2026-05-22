<?php

/**
 * Migration: Clarify fileUpdate AI Tool description and lineRange semantics
 *
 * - Tool description rewritten to make line-based vs content-based ops explicit.
 * - Per-parameter descriptions clarify:
 *   * startLine / endLine: 1-based, BOTH inclusive (the entire range is replaced).
 *   * content (lineRange / insertAtLine): a single trailing "\n" is auto-stripped
 *     because LLMs naturally end snippets with one; use "\n\n" to keep a real
 *     trailing blank line.
 * - Helps prevent off-by-one loops where Spirit re-edits the same range trying
 *   to remove a phantom blank line that was actually caused by a trailing
 *   newline in content (now fixed server-side too, see ProjectFileService).
 */
class UserMigration_20260521210000
{
    public function up(\PDO $db): void
    {
        $params = [
            'type' => 'object',
            'properties' => [
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Project ID'
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory path where the file is located'
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'The name of the file to update'
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['replace', 'lineRange', 'append', 'prepend', 'insertAtLine'],
                    'description' => 'Update operation type. Pick ONE per call. replace = literal find/replace (best for small unique snippets). lineRange = swap a range of lines for new content. insertAtLine = inject content before a given line. append/prepend = add to end/start of file.'
                ],
                'find' => [
                    'type' => 'string',
                    'description' => 'Text to find (for replace operation). Must match exactly, including whitespace and newlines.'
                ],
                'replaceWith' => [
                    'type' => 'string',
                    'description' => 'Text to replace `find` with (for replace operation). Use empty string to delete the matched text.'
                ],
                'startLine' => [
                    'type' => 'integer',
                    'description' => 'Start line number for lineRange (1-based, inclusive). Line is read with fileManage read + withLineNumbers=true.'
                ],
                'endLine' => [
                    'type' => 'integer',
                    'description' => 'End line number for lineRange (1-based, inclusive). Both startLine and endLine are part of the replaced range. To replace a single line use startLine == endLine.'
                ],
                'line' => [
                    'type' => 'integer',
                    'description' => 'Line number to insert at for insertAtLine (1-based). Content is inserted BEFORE this line, pushing the existing line down.'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content for lineRange, insertAtLine, append, or prepend. IMPORTANT: do NOT end with a trailing "\\n" — line separation is handled automatically and a trailing newline would create an unintended blank line (one trailing "\\n" is auto-stripped; use "\\n\\n" if you intentionally want a blank line at the end).'
                ]
            ],
            'required' => ['projectId', 'path', 'name', 'operation']
        ];

        $description = 'Update a file in place. One operation per call. ' .
            'Operations: ' .
            'replace (find + replaceWith — best for small, uniquely-identifiable snippets); ' .
            'lineRange (startLine + endLine + content, both bounds inclusive — best after fileManage read withLineNumbers=true); ' .
            'insertAtLine (line + content — inserts BEFORE the given line); ' .
            'append / prepend (content — adds to end/start of file). ' .
            'For content fields, do NOT add a trailing newline (it is auto-stripped to avoid phantom blank lines; use "\\n\\n" for an intentional trailing blank). ' .
            'For multiple edits in one file, call this tool multiple times.';

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            json_encode($params),
            $description,
            date('Y-m-d H:i:s'),
            'fileUpdate',
        ]);
    }

    public function down(\PDO $db): void
    {
        // Restore previous description / params from Version20260101210000
        $oldParams = [
            'type' => 'object',
            'properties' => [
                'projectId' => ['type' => 'string', 'description' => 'Project ID'],
                'path' => ['type' => 'string', 'description' => 'The directory path where the file is located'],
                'name' => ['type' => 'string', 'description' => 'The name of the file to update'],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['replace', 'lineRange', 'append', 'prepend', 'insertAtLine'],
                    'description' => 'Update operation type'
                ],
                'find' => ['type' => 'string', 'description' => 'Text to find (for replace operation)'],
                'replaceWith' => ['type' => 'string', 'description' => 'Text to replace with (for replace operation)'],
                'startLine' => ['type' => 'integer', 'description' => 'Start line number (for lineRange operation, 1-based)'],
                'endLine' => ['type' => 'integer', 'description' => 'End line number (for lineRange operation, 1-based, inclusive)'],
                'line' => ['type' => 'integer', 'description' => 'Line number to insert at (for insertAtLine operation, 1-based)'],
                'content' => ['type' => 'string', 'description' => 'Content for lineRange, append, prepend, or insertAtLine operations']
            ],
            'required' => ['projectId', 'path', 'name', 'operation']
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            json_encode($oldParams),
            'Update file content efficiently. One operation per call for better LLM compatibility. Operations: replace (find/replaceWith), lineRange (startLine/endLine/content), append/prepend/insertAtLine (content). For multiple edits, call multiple times.',
            date('Y-m-d H:i:s'),
            'fileUpdate',
        ]);
    }
}

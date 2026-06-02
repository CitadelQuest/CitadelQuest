<?php

/**
 * Migration: Simplify fileUpdate & fileManage AI Tools to use a single combined
 * `pathname` parameter instead of separate path + name.
 *
 * AI Spirits naturally reason about files as a full path including the filename
 * (e.g. "/spirit/Amidamaru/memory/notes.md"), so splitting them into a directory
 * `path` and a `name` caused frequent mistakes. The tool layer now splits the
 * combined pathname back into path + name via ProjectFileService::splitPathname()
 * at call start, so all downstream code is unchanged and the old path/name
 * parameters still work (backward compatible).
 *
 * - fileUpdate: path + name        -> pathname
 * - fileManage: sourcePath/Name    -> sourcePathname (copy, rename_move)
 *               destPath/Name       -> destPathname   (copy, rename_move)
 *               single-target ops    -> pathname (create, read, delete, createDirectory)
 *               list/tree            -> keep `path` (directory only, no filename)
 */
class UserMigration_20260602000000
{
    public function up(\PDO $db): void
    {
        $this->updateFileUpdate(
            $db,
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'pathname' => [
                        'type' => 'string',
                        'description' => 'Full path including filename of the file to update, e.g. "/spirit/Amidamaru/memory/notes.md".'
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
                'required' => ['projectId', 'pathname', 'operation']
            ],
            'Update a file in place. One operation per call. ' .
                'Identify the file with `pathname` (full path including filename, e.g. "/dir/sub/file.md"). ' .
                'Operations: ' .
                'replace (find + replaceWith — best for small, uniquely-identifiable snippets); ' .
                'lineRange (startLine + endLine + content, both bounds inclusive — best after fileManage read withLineNumbers=true); ' .
                'insertAtLine (line + content — inserts BEFORE the given line); ' .
                'append / prepend (content — adds to end/start of file). ' .
                'For content fields, do NOT add a trailing newline (it is auto-stripped to avoid phantom blank lines; use "\\n\\n" for an intentional trailing blank). ' .
                'For multiple edits in one file, call this tool multiple times.'
        );

        $this->updateFileManage(
            $db,
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['create', 'read', 'copy', 'rename_move', 'delete', 'createDirectory', 'list', 'tree'],
                        'description' => 'Type of file operation to perform. File operations: create, read, copy, rename_move, delete, createDirectory, list (list files in dir), tree (full project tree).'
                    ],
                    'pathname' => [
                        'type' => 'string',
                        'description' => 'Full path including filename, e.g. "/dir/sub/file.md". Use for create, read, delete, and createDirectory (the new directory full path) operations.'
                    ],
                    'sourcePathname' => [
                        'type' => 'string',
                        'description' => 'Full source path including filename. Use for copy and rename_move operations.'
                    ],
                    'destPathname' => [
                        'type' => 'string',
                        'description' => 'Full destination path including filename. Use for copy and rename_move operations.'
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Directory path only (no filename). Use for list and tree operations (default "/").'
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'File content (required for create operation)'
                    ],
                    'withLineNumbers' => [
                        'type' => 'boolean',
                        'description' => 'Whether to include line numbers in the output (for read operation)'
                    ]
                ],
                'required' => ['projectId', 'operation']
            ],
            'Unified file management operations. Supports operations: create, read, copy, rename_move, delete, createDirectory, list, tree. ' .
                'Identify files with a single full `pathname` (path including filename); ' .
                'for copy/rename_move use `sourcePathname` and `destPathname`; ' .
                'for list/tree use `path` (directory only).'
        );
    }

    public function down(\PDO $db): void
    {
        // Restore fileUpdate to Version20260521210000 (path + name).
        $this->updateFileUpdate(
            $db,
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => ['type' => 'string', 'description' => 'Project ID'],
                    'path' => ['type' => 'string', 'description' => 'The directory path where the file is located'],
                    'name' => ['type' => 'string', 'description' => 'The name of the file to update'],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['replace', 'lineRange', 'append', 'prepend', 'insertAtLine'],
                        'description' => 'Update operation type. Pick ONE per call. replace = literal find/replace (best for small unique snippets). lineRange = swap a range of lines for new content. insertAtLine = inject content before a given line. append/prepend = add to end/start of file.'
                    ],
                    'find' => ['type' => 'string', 'description' => 'Text to find (for replace operation). Must match exactly, including whitespace and newlines.'],
                    'replaceWith' => ['type' => 'string', 'description' => 'Text to replace `find` with (for replace operation). Use empty string to delete the matched text.'],
                    'startLine' => ['type' => 'integer', 'description' => 'Start line number for lineRange (1-based, inclusive). Line is read with fileManage read + withLineNumbers=true.'],
                    'endLine' => ['type' => 'integer', 'description' => 'End line number for lineRange (1-based, inclusive). Both startLine and endLine are part of the replaced range. To replace a single line use startLine == endLine.'],
                    'line' => ['type' => 'integer', 'description' => 'Line number to insert at for insertAtLine (1-based). Content is inserted BEFORE this line, pushing the existing line down.'],
                    'content' => ['type' => 'string', 'description' => 'Content for lineRange, insertAtLine, append, or prepend. IMPORTANT: do NOT end with a trailing "\\n" — line separation is handled automatically and a trailing newline would create an unintended blank line (one trailing "\\n" is auto-stripped; use "\\n\\n" if you intentionally want a blank line at the end).']
                ],
                'required' => ['projectId', 'path', 'name', 'operation']
            ],
            'Update a file in place. One operation per call. ' .
                'Operations: ' .
                'replace (find + replaceWith — best for small, uniquely-identifiable snippets); ' .
                'lineRange (startLine + endLine + content, both bounds inclusive — best after fileManage read withLineNumbers=true); ' .
                'insertAtLine (line + content — inserts BEFORE the given line); ' .
                'append / prepend (content — adds to end/start of file). ' .
                'For content fields, do NOT add a trailing newline (it is auto-stripped to avoid phantom blank lines; use "\\n\\n" for an intentional trailing blank). ' .
                'For multiple edits in one file, call this tool multiple times.'
        );

        // Restore fileManage to Version20260403150000 (sourcePath/Name + destPath/Name).
        $this->updateFileManage(
            $db,
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => ['type' => 'string', 'description' => 'Project ID'],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['create', 'read', 'copy', 'rename_move', 'delete', 'createDirectory', 'list', 'tree'],
                        'description' => 'Type of file operation to perform. File operations: create, read, copy, rename_move, delete, createDirectory, list (list files in dir), tree (full project tree).'
                    ],
                    'sourcePath' => ['type' => 'string', 'description' => 'Source file/directory path (required for copy, read, rename_move, delete, list, tree operations)'],
                    'sourceName' => ['type' => 'string', 'description' => 'Source file/directory name (required for copy, read, rename_move, delete operations)'],
                    'destPath' => ['type' => 'string', 'description' => 'Destination file/directory path (required for create, copy, rename_move, createDirectory operations)'],
                    'destName' => ['type' => 'string', 'description' => 'Destination file/directory name (required for create, copy, rename_move, createDirectory operations)'],
                    'content' => ['type' => 'string', 'description' => 'File content (required for create operation)'],
                    'withLineNumbers' => ['type' => 'boolean', 'description' => 'Whether to include line numbers in the output (for read operation)']
                ],
                'required' => ['projectId', 'operation']
            ],
            'Unified file management operations. Supports operations: create, read, copy, rename_move, delete, createDirectory, list, tree.'
        );
    }

    private function updateFileUpdate(\PDO $db, array $params, string $description): void
    {
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            json_encode($params),
            $description,
            date('Y-m-d H:i:s'),
            'fileUpdate',
        ]);
    }

    private function updateFileManage(\PDO $db, array $params, string $description): void
    {
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            json_encode($params),
            $description,
            date('Y-m-d H:i:s'),
            'fileManage',
        ]);
    }
}

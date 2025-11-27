/**
 * Service for interacting with the Project File API
 */
export class FileBrowserApiService {
    /**
     * @param {Object} options - Configuration options
     * @param {Object} options.translations - Translation strings
     */
    constructor(options) {
        this.translations = options.translations || {};
        this.baseUrl = '/api/project-file';
    }

    /**
     * List files in a directory
     * @param {string} projectId - The project ID
     * @param {string} path - The directory path
     * @returns {Promise<Object>} - JSON response with files
     */
    async listFiles(projectId, path = '/') {
        const url = `${this.baseUrl}/list/${projectId}?path=${encodeURIComponent(path)}`;
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(this.translations.failed_load || 'Failed to load files');
        }
        
        return await response.json();
    }

    /**
     * Get file metadata
     * @param {string} fileId - The file ID
     * @returns {Promise<Object>} - JSON response with file metadata
     */
    async getFileMetadata(fileId) {
        const response = await fetch(`${this.baseUrl}/${fileId}`);
        
        if (!response.ok) {
            throw new Error(this.translations.failed_load || 'Failed to load file metadata');
        }
        
        return await response.json();
    }

    /**
     * Get file content
     * @param {string} fileId - The file ID
     * @returns {Promise<Object>} - JSON response with file content
     */
    async getFileContent(fileId) {
        const response = await fetch(`${this.baseUrl}/${fileId}/content`);
        
        if (!response.ok) {
            throw new Error(this.translations.failed_load || 'Failed to load file content');
        }
        
        return await response.json();
    }

    /**
     * Download file
     * @param {string} fileId - The file ID
     */
    downloadFile(fileId) {
        window.location.href = `${this.baseUrl}/${fileId}/download`;
    }

    /**
     * Create directory
     * @param {string} projectId - The project ID
     * @param {string} path - The parent directory path
     * @param {string} name - The directory name
     * @returns {Promise<Object>} - JSON response with created directory
     */
    async createDirectory(projectId, path, name) {
        const response = await fetch(`${this.baseUrl}/${projectId}/directory`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ path, name })
        });
        
        if (!response.ok) {
            throw new Error(this.translations.failed_create || 'Failed to create directory');
        }
        
        return await response.json();
    }

    /**
     * Create file with content
     * @param {string} projectId - The project ID
     * @param {string} path - The parent directory path
     * @param {string} name - The file name
     * @param {string} content - The file content
     * @returns {Promise<Object>} - JSON response with created file
     */
    async createFile(projectId, path, name, content) {
        const response = await fetch(`${this.baseUrl}/${projectId}/file`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ path, name, content })
        });
        
        if (!response.ok) {
            throw new Error(this.translations.failed_create || 'Failed to create file');
        }
        
        return await response.json();
    }

    /**
     * Upload file
     * @param {string} projectId - The project ID
     * @param {string} path - The parent directory path
     * @param {File} file - The file to upload
     * @returns {Promise<Object>} - JSON response with uploaded file
     */
    async uploadFile(projectId, path, file) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('path', path);
        
        const response = await fetch(`${this.baseUrl}/${projectId}/upload`, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(this.translations.failed_upload || 'Failed to upload file');
        }
        
        return await response.json();
    }

    /**
     * Update file content
     * @param {string} fileId - The file ID
     * @param {string} content - The new content
     * @returns {Promise<Object>} - JSON response with updated file
     */
    async updateFile(fileId, content) {
        const response = await fetch(`${this.baseUrl}/${fileId}/content`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ content })
        });
        
        if (!response.ok) {
            throw new Error(this.translations.failed_update || 'Failed to update file');
        }
        
        return await response.json();
    }

    /**
     * Delete file or directory
     * @param {string} fileId - The file ID
     * @returns {Promise<boolean>} - True if successful
     */
    async deleteFile(fileId) {
        const response = await fetch(`${this.baseUrl}/${fileId}`, {
            method: 'DELETE'
        });
        
        if (!response.ok) {
            throw new Error(this.translations.failed_delete || 'Failed to delete file');
        }
        
        return true;
    }

    /**
     * Get file versions
     * @param {string} fileId - The file ID
     * @returns {Promise<Object>} - JSON response with file versions
     */
    async getFileVersions(fileId) {
        const response = await fetch(`${this.baseUrl}/versions/${fileId}`);
        
        if (!response.ok) {
            throw new Error(this.translations.failed_load || 'Failed to load file versions');
        }
        
        return await response.json();
    }
    
    /**
     * Get the complete project tree structure
     * @param {string} projectId - The project ID
     * @returns {Promise<Object>} - JSON response with the complete hierarchical tree structure
     */
    async getProjectTree(projectId) {
        const response = await fetch(`${this.baseUrl}/${projectId}/tree`);
        
        if (!response.ok) {
            throw new Error(this.translations.failed_load || 'Failed to load project tree');
        }
        
        return await response.json();
    }

    /**
     * Manage file operations: copy, move/rename, delete
     * @param {string} projectId - The project ID
     * @param {string} operation - Operation type: 'copy', 'rename_move', 'delete'
     * @param {Object} source - Source file { path, name }
     * @param {Object|null} destination - Destination { path, name } (not needed for delete)
     * @returns {Promise<Object>} - JSON response with operation result
     */
    async manageFile(projectId, operation, source, destination = null) {
        const body = { operation, source };
        if (destination) {
            body.destination = destination;
        }

        const response = await fetch(`${this.baseUrl}/${projectId}/manage`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(body)
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || this.translations.failed_operation || 'Operation failed');
        }
        
        return await response.json();
    }

    /**
     * Copy file or directory
     * @param {string} projectId - The project ID
     * @param {Object} source - Source { path, name }
     * @param {Object} destination - Destination { path, name }
     */
    async copyFile(projectId, source, destination) {
        return this.manageFile(projectId, 'copy', source, destination);
    }

    /**
     * Move or rename file/directory
     * @param {string} projectId - The project ID
     * @param {Object} source - Source { path, name }
     * @param {Object} destination - Destination { path, name }
     */
    async moveFile(projectId, source, destination) {
        return this.manageFile(projectId, 'rename_move', source, destination);
    }

    /**
     * Rename file/directory (convenience method - same path, different name)
     * @param {string} projectId - The project ID
     * @param {string} path - File path
     * @param {string} oldName - Current name
     * @param {string} newName - New name
     */
    async renameFile(projectId, path, oldName, newName) {
        return this.manageFile(projectId, 'rename_move', 
            { path, name: oldName }, 
            { path, name: newName }
        );
    }
}

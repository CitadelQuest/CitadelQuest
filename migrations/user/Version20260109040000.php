<?php

/**
 * Migration: Clean up duplicate AI models
 * 
 * This migration fixes an issue where AI models were being duplicated
 * on each sync instead of being updated. It clears all models and
 * resets related settings to trigger a fresh sync.
 */
class UserMigration_20260109040000
{
    public function getDescription(): string
    {
        return 'Clean up duplicate AI models and reset sync settings';
    }

    public function up(\PDO $pdo): void
    {
        // 1. Delete all AI service models (will be re-synced on next login)
        $pdo->exec('DELETE FROM ai_service_model');
        
        // 2. Reset primary AI service model ID
        $stmt = $pdo->prepare('UPDATE settings SET value = NULL WHERE key = ?');
        $stmt->execute(['ai.primary_ai_service_model_id']);
        
        // 3. Reset secondary AI service model ID
        $stmt = $pdo->prepare('UPDATE settings SET value = NULL WHERE key = ?');
        $stmt->execute(['ai.secondary_ai_service_model_id']);
        
        // 4. Reset models list updated_at to force re-sync
        $stmt = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
        $stmt->execute(['2020-01-01 00:00:00', 'ai_models_list.updated_at']);
        
        // 5. Reset models count
        $stmt = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
        $stmt->execute(['0', 'ai_models_list.count']);
    }

    public function down(\PDO $pdo): void
    {
        // Cannot restore deleted models - they will be re-synced automatically
        // This is a one-way migration for cleanup purposes
    }
}

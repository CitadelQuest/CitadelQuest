<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for the general multi-purpose File Browser
 */
#[IsGranted('ROLE_USER')]
class FileBrowserController extends AbstractController
{
    /**
     * Show the file browser
     */
    #[Route('/file-browser', name: 'app_file_browser')]
    public function index(TranslatorInterface $translator): Response
    {
        // Get translations for the file browser
        $translations = [
            'new_folder' => $translator->trans('file_browser.new_folder'),
            'new_file' => $translator->trans('file_browser.new_file'),
            'upload' => $translator->trans('file_browser.upload'),
            'download' => $translator->trans('file_browser.download'),
            'delete' => $translator->trans('file_browser.delete'),
            'loading' => $translator->trans('file_browser.loading'),
            'empty_directory' => $translator->trans('file_browser.empty_directory'),
            'no_file_selected' => $translator->trans('file_browser.no_file_selected'),
            'select_file_to_preview' => $translator->trans('file_browser.select_file_to_preview'),
            'loading_preview' => $translator->trans('file_browser.loading_preview'),
            'error_loading_preview' => $translator->trans('file_browser.error_loading_preview'),
            'directory' => $translator->trans('file_browser.directory'),
            'directory_info' => $translator->trans('file_browser.directory_info'),
            'no_preview' => $translator->trans('file_browser.no_preview'),
            'audio_not_supported' => $translator->trans('file_browser.audio_not_supported'),
            'video_not_supported' => $translator->trans('file_browser.video_not_supported'),
            'enter_folder_name' => $translator->trans('file_browser.enter_folder_name'),
            'enter_file_name' => $translator->trans('file_browser.enter_file_name'),
            'enter_file_content' => $translator->trans('file_browser.enter_file_content'),
            'folder_created' => $translator->trans('file_browser.folder_created'),
            'file_created' => $translator->trans('file_browser.file_created'),
            'file_uploaded' => $translator->trans('file_browser.file_uploaded'),
            'confirm_delete_directory' => $translator->trans('file_browser.confirm_delete_directory'),
            'confirm_delete_file' => $translator->trans('file_browser.confirm_delete_file'),
            'directory_deleted' => $translator->trans('file_browser.directory_deleted'),
            'file_deleted' => $translator->trans('file_browser.file_deleted'),
            'drag_files' => $translator->trans('file_browser.drag_files'),
            'or' => $translator->trans('file_browser.or'),
            'browse_files' => $translator->trans('file_browser.browse_files'),
            'search_placeholder' => $translator->trans('file_browser.search_placeholder'),
            'no_results' => $translator->trans('file_browser.no_results'),
            'show_gallery' => $translator->trans('file_browser.show_gallery'),
            'hide_gallery' => $translator->trans('file_browser.hide_gallery'),
            'loading_images' => $translator->trans('file_browser.loading_images'),
            'no_images' => $translator->trans('file_browser.no_images'),
            'download_zip' => $translator->trans('file_browser.download_zip'),
            'preparing_zip' => $translator->trans('file_browser.preparing_zip'),
        ];

        return $this->render('file_browser/index.html.twig', [
            'translations' => json_encode($translations),
            'project_id' => 'general', // Using a fixed project ID for the general multi-purpose file browser
        ]);
    }
}

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
            'share' => $translator->trans('ui.share'),
            'shared_edit' => $translator->trans('file_browser.shared_edit'),
            'share_updated' => $translator->trans('file_browser.share_updated'),
            'edit_share_title' => $translator->trans('cq_share.edit_title'),
            'field_title' => $translator->trans('cq_share.field_title'),
            'field_url_slug' => $translator->trans('cq_share.field_url_slug'),
            'field_scope' => $translator->trans('cq_share.field_scope'),
            'scope_contacts' => $translator->trans('cq_share.scope_contacts_desc'),
            'scope_public' => $translator->trans('cq_share.scope_public_desc'),
            'field_description' => $translator->trans('cq_share.field_description'),
            'field_description_placeholder' => $translator->trans('cq_share.field_description_placeholder'),
            'field_desc_position' => $translator->trans('cq_share.field_desc_display_style'),
            'desc_above' => $translator->trans('cq_share.desc_style_above'),
            'desc_below' => $translator->trans('cq_share.desc_style_below'),
            'desc_left' => $translator->trans('cq_share.desc_style_left'),
            'desc_right' => $translator->trans('cq_share.desc_style_right'),
            'field_display_style' => $translator->trans('cq_share.field_display_style'),
            'display_off' => $translator->trans('cq_share.display_style_off'),
            'display_preview' => $translator->trans('cq_share.display_style_preview'),
            'display_full' => $translator->trans('cq_share.display_style_full'),
            'field_status' => $translator->trans('cq_share.field_status'),
            'status_active' => $translator->trans('cq_share.field_status_active'),
            'cancel' => $translator->trans('ui.cancel'),
            'save' => $translator->trans('cq_share.save'),
        ];

        return $this->render('file_browser/index.html.twig', [
            'translations' => json_encode($translations),
            'project_id' => 'general', // Using a fixed project ID for the general multi-purpose file browser
        ]);
    }
}

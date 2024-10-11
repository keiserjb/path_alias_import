<?php

namespace Drupal\amu_path_alias_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\redirect\RedirectRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AmuPathAliasImportForm.
 *
 * Provides a form to import path aliases from a CSV file.
 */
class AmuPathAliasImportForm extends FormBase {

  /**
   * The alias repository service.
   *
   * @var \Drupal\path_alias\AliasRepositoryInterface
   */
  protected $aliasRepository;

  /**
   * The redirect repository service.
   *
   * @var \Drupal\redirect\RedirectRepository
   */
  protected $redirectRepository;

  /**
   * Constructs the form object.
   *
   * @param \Drupal\path_alias\AliasRepositoryInterface $aliasRepository
   *   The alias repository service.
   * @param \Drupal\redirect\RedirectRepository $redirectRepository
   *   The redirect repository service.
   */
  public function __construct(AliasRepositoryInterface $aliasRepository, RedirectRepository $redirectRepository) {
    $this->aliasRepository = $aliasRepository;
    $this->redirectRepository = $redirectRepository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path_alias.repository'),
      $container->get('redirect.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'amu_path_alias_import_form';
  }

  /**
   * Build the form for uploading the CSV file.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['path_alias_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Path alias CSV'),
      '#description' => $this->t('Upload a CSV file with 3 columns: alias, system path, and langcode.'),
      '#upload_location' => 'private://amu_path_auto_import',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import aliases'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Submit handler for the form.
   *
   * @param array &$form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get the uploaded file ID.
    $form_file = $form_state->getValue('path_alias_csv', 0);
    if (isset($form_file[0]) && !empty($form_file[0])) {
      // Load the file entity.
      $file = File::load($form_file[0]);
      $file_uri = $file->getFileUri();

      // Process the CSV if the file exists.
      if ($file_uri) {
        $aliasToSystemPathMappingArray = $this->extractCSVDatas($file_uri);
        if ($aliasToSystemPathMappingArray) {
          $this->savePathAlias($aliasToSystemPathMappingArray);
          \Drupal::messenger()->addMessage($this->t('Aliases successfully imported.'));
        } else {
          \Drupal::messenger()->addError($this->t('No valid aliases found in the file.'));
        }
      }
    }
  }

  /**
   * Saves path aliases and creates redirects if needed.
   *
   * @param array $aliasToSystemPathMappingArray
   *   An array of path aliases and system paths from the CSV file.
   */
  public function savePathAlias(array $aliasToSystemPathMappingArray) {
    foreach ($aliasToSystemPathMappingArray as $line) {
      list($path_alias, $system_path, $langcode) = $line;

      // Ensure the system path and alias start with a '/'.
      $system_path = '/' . ltrim($system_path, '/');
      $path_alias = '/' . ltrim($path_alias, '/');

      // Entity query to find existing aliases by source path with access check disabled.
      $existing_aliases = \Drupal::entityQuery('path_alias')
        ->condition('path', $system_path)
        ->condition('langcode', $langcode)
        ->accessCheck(FALSE)
        ->execute();

      $aliasIsExisting = FALSE;
      if (!empty($existing_aliases)) {
        foreach ($existing_aliases as $alias_id) {
          $existing_alias = \Drupal::entityTypeManager()->getStorage('path_alias')->load($alias_id);
          if ($existing_alias && $existing_alias->getAlias() === $path_alias) {
            $aliasIsExisting = TRUE;
          }
        }
      }

      // If an alias exists, create a redirect before replacing it.
      if ($aliasIsExisting) {
        foreach ($existing_aliases as $alias_id) {
          $existing_alias = \Drupal::entityTypeManager()->getStorage('path_alias')->load($alias_id);
          if ($existing_alias) {
            // Create a redirect from the old alias to the new one using the entity system.
            $redirect = \Drupal::entityTypeManager()->getStorage('redirect')->create([
              'redirect_source' => [
                'path' => $existing_alias->getAlias(),
                'query' => [],
              ],
              'redirect_redirect' => [
                'uri' => 'internal:' . $system_path,
              ],
              'language' => $langcode,
              'status_code' => 301,
            ]);
            $redirect->save();

            // Delete the old alias.
            $existing_alias->delete();
          }
        }
      }

      // Create and save the new alias.
      $alias = \Drupal::entityTypeManager()->getStorage('path_alias')->create([
        'path' => $system_path,
        'alias' => $path_alias,
        'langcode' => $langcode,
      ]);
      $alias->save();
    }
  }

  /**
   * Extracts data from the uploaded CSV file.
   *
   * @param string $file_uri
   *   The file URI of the uploaded CSV.
   *
   * @return array
   *   An array containing alias, system path, and langcode.
   */
  public function extractCSVDatas($file_uri) {
    $aliasToSystemPathMappingArray = [];

    // Open the CSV file and read its contents.
    if (($handle = fopen($file_uri, "r")) !== FALSE) {
      $csv = array_map('str_getcsv', file($file_uri));

      // Skip the header and process each line.
      foreach (array_slice($csv, 1) as $line) {
        // Expecting pid, source (system_path), alias, language
        if (isset($line[2]) && isset($line[1]) && isset($line[3])) {
          $alias = $line[2];
          $system_path = $line[1]; // Source path from the CSV
          $langcode = $line[3];
          $aliasToSystemPathMappingArray[] = [$alias, $system_path, $langcode];
        }
      }
      fclose($handle);
    }

    return $aliasToSystemPathMappingArray;
  }


}

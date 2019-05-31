<?php /**
 * @file
 * Contains Drupal\amu_path_alias_import\Form.
 *
 * @author m.dandonneau
 *
 */

namespace Drupal\amu_path_alias_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Class AmuPathAliasImportForm
 *
 * @package Drupal\amu_path_alias_import\\Form
 */
class AmuPathAliasImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {

    return 'AmuPathAliasImportForm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['path_alias_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('path alias csv'),
      '#description' => t('upload a csv file containing 4 columns with an entry id, /alias, the /node/id and the langcode.The first line being the array header is not considered'),
      '#upload_location' => 'private://amu_path_auto_import',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
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
   * {@inheritdoc}
   *
   * @author m.Dandonneau
   *
   *
   * upload csv file and add aliases
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($_POST['op'] == 'Import aliases') {
      $form_file = $form_state->getValue('path_alias_csv', 0);
      if (isset($form_file[0]) && !empty($form_file[0])) {
        $file = File::load($form_file[0]);
        $file_uri = $file->getFileUri();
      }
      if ($file_uri) {
        $AliasToSystemPathMappingArray = $this->extractCSVDatas($file_uri);
      }
      if ($AliasToSystemPathMappingArray) {
        $pathWithNewAlias = $this->savePathAlias($AliasToSystemPathMappingArray);
      }
      \Drupal::messenger()
        ->addMessage(t(count($pathWithNewAlias) . 'Alias ajouté(s) au(x) @path', [
          '@path' => json_encode($pathWithNewAlias),
        ]));
      \Drupal::logger('amu_path_auto_import')
        ->notice($this->t('Alias ajouté(s) au(x) @path', [
          '@path' => json_encode($pathWithNewAlias),
        ]));
    }
  }

  /**
   * @param $AliasToSystemPathMappingArray
   *
   * @return array
   *
   */
  public function savePathAlias($AliasToSystemPathMappingArray) {

    $pathWithNewAlias = [];
    foreach ($AliasToSystemPathMappingArray as $line) {
      list($path_alias, $system_path, $langcode) = $line;

      //l'alias n'est pas appliqué aux autres domaines
      if (strpos($path_alias, 'www.univ-amu.fr') == FALSE) {
        continue;
      }
      if (strpos($system_path, 'www.univ-amu.fr') == FALSE) {
        continue;
      }
      //l'alias n'est pas appliqué sur  une destination intramu
      if (strpos($system_path, 'intramu') !== FALSE) {
        continue;
      }

      //transforme les chemin absolu en chemin relatif
      if (strpos($path_alias, "amu.fr") !==false) {
        $path_alias = substr($path_alias, strpos($path_alias, "amu.fr") + 6);
      }
      if (strpos($system_path, "amu.fr") !==false) {
        $system_path = substr($system_path, strpos($system_path, "amu.fr") + 6);
      }

      //trim le prefixe de langue quand il existe
      if (strpos($path_alias, "/fr/") !==false) {
        $path_alias = substr($path_alias, strpos($path_alias, "/fr") + 3);
      }
      if (strpos($system_path, "/fr/")!==false) {
        $system_path = substr($system_path, strpos($system_path, "/fr") + 3);
      }
      if (strpos($path_alias, "/en/") !==false) {
        $path_alias = substr($path_alias, strpos($path_alias, "/en") + 3);
      }
      if (strpos($system_path, "/en/")!==false) {
        $system_path = substr($system_path, strpos($system_path, "/en") + 3);
      }
      if (strpos($path_alias, "/es/") !==false) {
        $path_alias = substr($path_alias, strpos($path_alias, "/es") + 3);
      }
      if (strpos($system_path, "/es/") !==false) {
        $system_path = substr($system_path, strpos($system_path, "/es") + 3);
      }

      //transforme l'alias de destination en system path de destination
      $system_path = \Drupal::service('path.alias_manager')
        ->getPathByAlias($system_path, $langcode);

      //langcode par defaut
      if (NULL == $langcode) {
        $langcode = 'und';
      }

      // Si system path valide ( /node/id ) , cad si l'alias de destination existe et a pu être convertit
      if (substr($system_path, 0, 6) === "/node/") {
        {
          $aliases = \Drupal::database()->query('
  SELECT alias
  FROM {url_alias}
  WHERE source = :source
', [':source' => $system_path])->fetchAll();

          $aliasIsExisting = FALSE;
          foreach ($aliases as $alias) {
            if ($path_alias == $alias->alias) {
              $aliasIsExisting = TRUE;
            }
          }
          //Si l'alias n'existe pas déja pour le noeud
          if (FALSE == $aliasIsExisting) {
            //alias ajouté
            $pathWithNewAlias[] = \Drupal::service('path.alias_storage')
              ->save($system_path, $path_alias, $langcode);
          }
        }
      }
    }

    return $pathWithNewAlias;
  }


  public
  function extractCSVDatas($file_uri) {
    $AliasToSystemPathMappingArray = [];

    if (($handle = fopen($file_uri, "r")) !== FALSE) {
      $csv = array_map('str_getcsv', file($file_uri));

      //on enleve le header et on alimente le tableau avec les colonnes qui nous interesse
      foreach ((array_slice($csv, 1)) as $line) {
        list($id, $alias, $systemPath, $langcode) = explode(';', $line[0]);
        $AliasToSystemPathMappingArray[] = [$alias, $systemPath, $langcode];
      }
      fclose($handle);
    }
    return $AliasToSystemPathMappingArray;
  }

}

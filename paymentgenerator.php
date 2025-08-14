<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.paymentgenerator
 * @copyright   Copyright (C) 2024 Jlowcode Org - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\Date\Date;

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

/**
 * Verifies the current form and generates information for the chosen group and field.
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.paymentgenerator
 * @since       3.0
 */
class PlgFabrik_FormPaymentgenerator extends PlgFabrik_Form 
{
    private $prefix;
    private $table;
    private $pluginParams;
    private $columns;
    private $defaultSituationForPayments;

    /**
     * Run right at the end of the form processing
     * form needs to be set to record in database for this to hook to be called
     *
     * @return	    bool
     */
    public function onAfterProcess()
    {
        $params    = $this->getParams();
        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();
        $elements = $listModel->getElements('id');

        if(!$this->mustRun()) {
            return;
        }

        $this->setPluginParams();
        $this->setTableForQuery();
        $this->setColumnsForQuery();
        $this->setDefaultSituationForPayments();

        $formData = $formModel->formData;
        $origFormData = $formModel->getOrigData()[0];
        $piType = is_array($formData['tipo']) ? $formData['tipo'][0] : $formData['tipo'];
        $situation = is_array($formData['situacao_pi']) ? $formData['situacao_pi'][0] : $formData['situacao_pi'];
        $idPi = $formData['id'];

        $qtnPayments = $this->countQtnPayments($idPi);

        if(!$this->checkToGenerate($formData)) {
            return;
        }

        switch ($piType) {
            case 'Patente-de-Invencao':
                if(in_array($situation,['Sigilo INPI', 'Pedido de Proteção Depositado']) && $qtnPayments > 0) {
                    $this->generateInventionPatentP($formData);
                } elseif ($this->checkPatentGrant($situation, $formData, $origFormData)) {
                    $this->generateInventionPatentPG($formData);
                }
                break;

            case 'Modelo-de-utilidade':
                if(in_array($situation,['Sigilo INPI', 'Pedido de Proteção Depositado']) && $qtnPayments > 0) {
                    $this->generateUtilityModelP($formData);
                } elseif ($this->checkPatentGrant($situation, $formData, $origFormData)) {
                    $this->generateUtilityModelPG($formData);
                }
                break;

            case 'Desenho-industrial':
                if($qtnPayments > 0) {
                    $this->generateIndustrialDesign($formData);
                }
                break;

            case 'Marca':
                if($situation == 'Pedido de Proteção Depositado' && $qtnPayments > 0) {
                    $this->generateTrademarkP($formData);
                } elseif ($this->checkPatentGrant($situation, $formData, $origFormData)) {
                    $this->generateTrademarkPG($formData);
                }
                break;

            case 'Protecao-Cultivar':
                if($qtnPayments > 1) {
                    $this->generatePlantVarietyProtection($formData);
                }
                break;

            case 'Programa-de-Computador':
            case 'Cultivar':
            case 'Topografia-de-Circuito-Integrado':
            case 'Indicacao-Geografica':
                // No payment generation for those type
                break;
        }

        return true;
    }

    /**
     * This method generates the payment for the Invention Patent.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generateInventionPatentP($formData)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $alreadyGenerated = $this->findPayment($formData['id'], 'PI-EXAME TECNICO');

        if($alreadyGenerated) {
            return;
        }

        $subValues = $this->getValuesForCategorys();
        $categories = $this->getCategoriesForPatentNotGranted($subValues);

        $depositDate = $formData[$this->getNameElementForQuery('pg_element_start')][0];
        $pricesInventionPatentP = $this->validatePrices('pg_prices_invention_patent_p');

        if(!$this->validateDate($depositDate)) {
            return;
        }

        // Insert the tecnic examination payment
        $data = Array(
            'PI-EXAME TECNICO',                                         // Category
            $depositDate,                                               // Start date   
            $this->calculationDate('+3 years', $depositDate),           // Ordinary end date
            $db->getNullDate(),                                         // Extraordinary end date    
            $pricesInventionPatentP[0],                                 // Price  
            $this->defaultSituationForPayments,                         // Situation
            $this->calculationDate('+1 year', $depositDate)             // Alert date
        );
        $this->insertPayment($data);

        // Insert the annuity payments
        $indexStart = 2;
        foreach ($categories as $key => $category) {
            $start = $this->calculationDate("+$indexStart year", $depositDate);
            $endO = $this->calculationDate("+$indexStart year +3 months", $depositDate);
            $endE = $this->calculationDate("+$indexStart year +9 months", $depositDate);
            $alert = $this->calculationDate("+$indexStart year +1 month", $depositDate);

            $price = $pricesInventionPatentP[$key] ?? $pricesInventionPatentP[count($pricesInventionPatentP) - 1];

            $data = Array(
                $category,                              // Category
                $start,                                 // Start date
                $endO,                                  // Ordinary end date
                $endE,                                  // Extraordinary end date
                $price,                                 // Price
                $this->defaultSituationForPayments,     // Situation
                $alert                                  // Alert date
            );
            $this->insertPayment($data);

            $indexStart++;
        }
    }

    /**
     * This method update the categories for the Invention Patent payment when the situation is changed to Patent Grant.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generateInventionPatentPG($formData)
    {
        $idPi = $formData['id'];

        $subValues = $this->getValuesForCategorys();
        $indexNextToPay = $this->checkIndexNextPayment($formData);
        $categories = array_values($this->searchCategorys($subValues, ' CP'));
        $pricesInventionPatentPG = $this->validatePrices('pg_prices_invention_patent_pg');
        $rowsPayments = $this->getRowsPayments($idPi);

        // To make the indexes equal to $formData, add this values to the arrays
        array_unshift($categories, 'PI-EXAME TECNICO');
        array_unshift($categories, 'PI-TAXA DE DEPOSITO');
        array_unshift($pricesInventionPatentPG, '');

        // Update categories and prices for the payments that still need to be paid
        for ($i=$indexNextToPay; $i < count($rowsPayments); $i++) { 
            $id = $rowsPayments[$i]->id;
            $oldCategory = $rowsPayments[$i]->categoria;
            $arrNewCategory = array_filter($categories, function ($item) use ($oldCategory) {
                return stripos($item, $oldCategory) !== false;
            });
            $newCategory = array_values($arrNewCategory)[0];
            $newPrice = $pricesInventionPatentPG[array_keys($arrNewCategory)[0]] ?? $pricesInventionPatentPG[count($pricesInventionPatentPG) - 1];

            if(stripos($oldCategory, 'ANUIDADE') === false || !isset($newCategory)) {
                continue;
            }

            $this->updatePaymentForPatentGrant($id, $newCategory, $newPrice);
        }
    }

    /**
     * This method generates the payment for the Utility Model.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generateUtilityModelP($formData)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $alreadyGenerated = $this->findPayment($formData['id'], 'MU-EXAME TECNICO');

        if($alreadyGenerated) {
            return;
        }

        $subValues = $this->getValuesForCategorys();
        $categories = $this->getCategoriesForPatentNotGranted($subValues);
        $depositDate = $formData[$this->getNameElementForQuery('pg_element_start')][0];
        $pricesUtilityModelP = $this->validatePrices('pg_prices_utility_model_p');

        if(!$this->validateDate($depositDate)) {
            return;
        }

        // Insert the tecnic examination payment
        $data = Array(
            'MU - EXAME TÉCNICO',                                   // Category
            $depositDate,                                           // Start date
            $this->calculationDate('+3 years', $depositDate),       // Ordinary end date
            $db->getNullDate(),                                     // Extraordinary end date    
            $pricesUtilityModelP[0],                                // Price
            $this->defaultSituationForPayments,                     // Situation
            $this->calculationDate('+1 year', $depositDate)         // Alert date
        );
        $this->insertPayment($data);

        // Insert the annuity payments
        $indexStart = 2;
        foreach ($categories as $key => $category) {
            $start = $this->calculationDate("+$indexStart year", $depositDate);
            $endO = $this->calculationDate("+$indexStart year +3 months", $depositDate);
            $endE = $this->calculationDate("+$indexStart year +9 months", $depositDate);
            $alert = $this->calculationDate("+$indexStart year +1 month", $depositDate);

            $price = $pricesUtilityModelP[$key] ?? $pricesUtilityModelP[count($pricesUtilityModelP) - 1];

            $data = Array(
                $category,                              // Category
                $start,                                 // Start date
                $endO,                                  // Ordinary end date
                $endE,                                  // Extraordinary end date
                $price,                                 // Price
                $this->defaultSituationForPayments,     // Situation
                $alert                                  // Alert date
            );
            $this->insertPayment($data);

            $indexStart++;
        }
    }

    /**
     * This method update the categories for the Utility Model payment when the situation is changed to Patent Grant.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generateUtilityModelPG($formData)
    {
        $idPi = $formData['id'];

        $subValues = $this->getValuesForCategorys();
        $indexNextToPay = $this->checkIndexNextPayment($formData);
        $categories = array_values($this->searchCategorys($subValues, ' CP'));
        $pricesInventionPatentPG = $this->validatePrices('pg_prices_utility_model_pg');
        $rowsPayments = $this->getRowsPayments($idPi);

        // To make the indexes equal to $formData, add this values to the arrays
        array_unshift($categories, 'MU - EXAME TÉCNICO');
        array_unshift($categories, 'MU - TAXA DE DEPÓSITO');
        array_unshift($pricesInventionPatentPG, '');

        // Update categories and prices for the payments that still need to be paid
        for ($i=$indexNextToPay; $i < count($rowsPayments); $i++) {
            $id = $rowsPayments[$i]->id;
            $oldCategory = $rowsPayments[$i]->categoria;
            $arrNewCategory = array_filter($categories, function ($item) use ($oldCategory) {
                return stripos($item, $oldCategory) !== false;
            });
            $newCategory = array_values($arrNewCategory)[0];
            $newPrice = $pricesInventionPatentPG[array_keys($arrNewCategory)[0]] ?? $pricesInventionPatentPG[count($pricesInventionPatentPG) - 1];

            if(stripos($oldCategory, 'ANUIDADE') === false || !isset($newCategory)) {
                continue;
            }

            $this->updatePaymentForPatentGrant($id, $newCategory, $newPrice);
        }
    }

    /**
     * This method generates the payment for the Industrial Design.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generateIndustrialDesign($formData)
    {
        $alreadyGenerated = $this->findPayment($formData['id'], 'DI-2ª PER.QUINQUENIO ');

        if($alreadyGenerated) {
            return;
        }

        $subValues = $this->getValuesForCategorys();
        $categories = array_values($this->searchCategorys($subValues, 'QUINQUENIO'));
        $depositDate = $formData[$this->getNameElementForQuery('pg_element_start')][0];
        $pricesIndustrialDesign = $this->validatePrices('pg_prices_industrial_design');

        if(!$this->validateDate($depositDate)) {
            return;
        }

        // Insert the annuity payments
        $indexStart = 4;
        foreach ($categories as $key => $category) {
            $indexEnd = $indexStart + 1;
            $start = $this->calculationDate("+$indexStart year", $depositDate);
            $endO = $this->calculationDate("+$indexEnd years", $depositDate);
            $endE = $this->calculationDate("+$indexEnd years +6 months", $depositDate);
            $alert = $this->calculationDate("+$indexStart years +6 months", $depositDate);

            $price = $pricesIndustrialDesign[$key] ?? $pricesIndustrialDesign[count($pricesIndustrialDesign) - 1];

            $data = Array(
                $category,                              // Category
                $start,                                 // Start date
                $endO,                                  // Ordinary end date
                $endE,                                  // Extraordinary end date
                $price,                                 // Price
                $this->defaultSituationForPayments,     // Situation
                $alert                                  // Alert date
            );
            $this->insertPayment($data);

            $indexStart += 5;
        }
    }

    /**
     * This method generates the payment for the Trademark.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generateTrademarkP($formData)
    {
        $idRowConcessionTax = $this->findPayment($formData['id'], 'M-TAXA DE CONCESSAO');

        if(!$idRowConcessionTax) {
            return;
        }

        $subValues = $this->getValuesForCategorys();
        $concessionDate = $this->getDateByIdPayment($idRowConcessionTax);
        $pricesInventionPatentP = $this->validatePrices('pg_prices_trademark');

        if(!$this->validateDate($concessionDate)) {
            return;
        }

        // Delete the previous payment for the concession
        $this->deleteRowPayment($idRowConcessionTax);

        // Insert the data about the concession payment 
        $data = Array(
            'M-TAXA DE CONCESSAO',                                      // Category
            $concessionDate,                                            // Start date
            $this->calculationDate('+60 days', $concessionDate),        // Ordinary end date
            $this->calculationDate('+150 days', $concessionDate),       // Extraordinary end date
            $pricesInventionPatentP[0],                                 // Price
            $this->defaultSituationForPayments,                         // Situation
            $this->calculationDate('+30 days', $concessionDate)         // Alert date
        );
        $this->insertPayment($data);
    }

    /**
     * This method generates the payment for the Trademark when it is changed to Patent Grant.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generateTrademarkPG($formData)
    {
        $alreadyGenerated = $this->findPayment($formData['id'], 'M-1ª PRORROGACAO');
        $startDate = $formData['data_situacao'];

        if($alreadyGenerated) {
            return;
        }

        if(!$startDate) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_DATE_MISSING_FOR_TRADEMARK_PG'));
            return;
        }

        if(!$this->validateDate($startDate)) {
            return;
        }

        $subValues = $this->getValuesForCategorys();
        $categories = $this->searchCategorys($subValues, 'PRORROGACAO');

        $pricesInventionPatentP = $this->validatePrices('pg_prices_trademark');
        array_shift($pricesInventionPatentP); // Remove the first element, which is not a price for the annuity

        // Insert the annuity payments
        $indexStart = 9;
        foreach ($categories as $key => $category) {
            $indexEnd = $indexStart + 1;
            $start = $this->calculationDate("+$indexStart year", $startDate);
            $endO = $this->calculationDate("+$indexEnd year", $startDate);
            $endE = $this->calculationDate("+$indexEnd year +6 months", $startDate);
            $alert = $this->calculationDate("+$indexStart year +9 months", $startDate);

            $price = $pricesInventionPatentP[$key] ?? $pricesInventionPatentP[count($pricesInventionPatentP) - 1];

            $data = Array(
                $category,                              // Category
                $start,                                 // Start date
                $endO,                                  // Ordinary end date
                $endE,                                  // Extraordinary end date
                $price,                                 // Price
                $this->defaultSituationForPayments,     // Situation
                $alert                                  // Alert date
            );
            $this->insertPayment($data);

            $indexStart += 10;
        }
    }

    /**
     * This method generates the payment for Plant Variety Protection.
     * 
     * @param       array       $formData       The form data to generate the payment.
     * 
     * @return      void
     */
    private function generatePlantVarietyProtection($formData)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $alreadyGenerated = $this->findPayment($formData['id'], 'C-1º MANUTENCAO');

        if($alreadyGenerated) {
            return;
        }

        $subValues = $this->getValuesForCategorys();
        $categories = array_values($this->searchCategorys($subValues, 'MANUTENCAO'));
        $idCertificatePayment = $this->findPayment($formData['id'], 'C-CERTIFICADO');
        $certificateDate = $this->getDateByIdPayment($idCertificatePayment);
        $pricesPlantVarietyProtection = $this->validatePrices('pg_prices_plant_variety_protection');

        if(!$idCertificatePayment) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_DATE_MISSING_FOR_PLANT_VARIETY_PROTECTION'));
            return;
        }

        if(!$this->validateDate($certificateDate) && $idCertificatePayment) {
            return;
        }

        // Insert the annuity payments
        $indexStart = 0;
        foreach ($categories as $key => $category) {
            $indexEnd = $indexStart + 1;
            $start = $this->calculationDate("+$indexStart year +6 months", $certificateDate);
            $endO = $this->calculationDate("+$indexEnd years", $certificateDate);
            $alert = $this->calculationDate("+$indexStart years +10 months", $certificateDate);

            $price = $pricesPlantVarietyProtection[$key] ?? $pricesPlantVarietyProtection[count($pricesPlantVarietyProtection) - 1];

            $data = Array(
                $category,                              // Category
                $start,                                 // Start date
                $endO,                                  // Ordinary end date
                $db->getNullDate(),                     // Extraordinary end date
                $price,                                 // Price
                $this->defaultSituationForPayments,     // Situation
                $alert                                  // Alert date
            );
            $this->insertPayment($data);

            $indexStart++;
        }
    }

    /**
     * This method searches for a payment in the database by its parent ID and category.
     * 
     * @param       int         $parentId       The ID of the parent record.
     * @param       string      $category       The category of the payment to search for.
     * 
     * @return      int
     */
    private function findPayment($parentId, $category)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->qn('id'))
            ->from($db->qn($this->table))
            ->where($db->qn('parent_id') . ' = ' . $db->q((int) $parentId))
            ->where($db->qn('categoria') . ' = ' . $db->q($category));
        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    /**
     * This method searches in the database for the date by the payment id
     * 
     * @param       int         $id               
     * 
     * @return      string
     */
    private function getDateByIdPayment($id)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->qn($this->getNameElementForQuery('pg_element_start')))
            ->from($db->qn($this->table))
            ->where($db->qn('id') . ' = ' . $db->q($id));
        $db->setQuery($query);

        return $db->loadResult();
    }

    /**
     * This method delete the payment row from the database.
     * 
     * @param       int         $rowId      The ID of the row to delete.
     * 
     * @return      void
     */
    private function deleteRowPayment($rowId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->delete($db->qn($this->table))
            ->where($db->qn('id') . ' = ' . (int) $rowId);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * This method get the subValues for the category element considering the current form.
     * 
     * @return      array
     */
    private function getValuesForCategorys()
    {
        $params = $this->getParams();
        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();
        $elements = $listModel->getElements('id');

        // Get the category element to get its options
        $categoryElementId = $params->get('pg_element_categories');
        $categoryElement  = $elements[$categoryElementId];
        $subValues = $categoryElement->getOptionValues();

        return $subValues;
    }

    /**
     * This method searches for categories in the subValues array for invention patents not including the ' CP' suffix.
     * 
     */
    private function getCategoriesForPatentNotGranted($subValues)
    {
        $categories = $this->searchCategorys($subValues, 'ANUIDADE');
        $categories = array_filter($categories, function($value) {
            return stripos($value, ' CP') === false;
        });

        return $categories;
    }
    /**
     * This method verify the last payment, and return the next payment index.
     * 
     */
    private function checkIndexNextPayment($formData)
    {
        $params    = $this->getParams();
        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();
        $elements = $listModel->getElements('id');

        // Get the situation element to check the last situation paied
        $situationElementId = $params->get('pg_element_situation');
        $situationElement  = $elements[$situationElementId];
        $nameSituationElement = $situationElement->element->name;

        $indexLastPaied = -1;
        foreach ($formData[$nameSituationElement] as $key => $item) {
            if(isset($item[0]) && $item[0] == 'Pago') {
                $indexLastPaied = $key;
            }

            // Break if the current situation is default and the previous one is 'Pago'
            if($formData[$nameSituationElement][$key-1][0] == 'Pago' && $formData[$nameSituationElement][$key][0] == $this->defaultSituationForPayments) {
                break;
            }
        }

        return $indexLastPaied+1;
    }
    /**
     * This method checks if the PI is capable to be changed to Patent Grant
     * 
     * Conditions to be true:
     * 1. The situation must be 'Concedido_Registrado'
     * 3. The form data must have the necessary fields to generate payments
     * 
     * @param       string      $situation      The current situation of the PI.
     * @param       array       $formData       The current form data.
     * @param       array       $origFormData   The original form data before the changes.
     * 
     * @return      bool
     */
    private function checkPatentGrant($situation, $formData, $origFormData)
    {
        $formModel = $this->getModel();
        $situationName = $formModel->getTableName() . '___situacao_pi';

        $isGranted = $situation === 'Concedido_Registrado';
        $alreadyGranted = false;

        $categories = $formData[$this->getNameElementForQuery('pg_element_categories')];
        foreach ($categories as $item) {
            if (isset($item[0]) && stripos($item[0], ' CP') !== false) {
                $alreadyGranted = true;
                break;
            }
        }

        return $isGranted && !$alreadyGranted && $this->checkToGenerate($formData);
    }

    /**
     * This method checks if the form data has the necessary fields to generate payments.
     * 
     * Conditions to be true:
     * 1. The first row of form data must have a category set
     * 2. The first row of form data must have a date set
     * 
     * @param       array       $formData  The form data to check.
     * 
     * @return      bool
     */
    private function checkToGenerate($formData)
    {
        $category = $formData[$this->getNameElementForQuery('pg_element_categories')][0][0];
        $hasCategory = isset($category);
        $hasExpectedCategory = stripos($category, 'TAXA DE PEDIDO') !== false || stripos($category, 'TAXA DE DEPOSITO') !== false || stripos($category, 'TAXA DE DEPÓSITO') !== false;
        $hasExpectedDate = isset($formData[$this->getNameElementForQuery('pg_element_start')][0]);

        return $hasCategory && $hasExpectedCategory && $hasExpectedDate;
    }

    /**
	 * This method says if the plugin must run or not
	 * 
	 * @return		bool
	 */
    private function mustRun()
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;
        $situation = is_array($formData['situacao_pi']) ? $formData['situacao_pi'][0] : $formData['situacao_pi'];
        $piType = $formData['tipo'][0];

        // If the form is new or the situation is not one of the expected ones, do not run
        if($formModel->isNewRecord() || !in_array($situation, ['Pedido de Proteção Depositado', 'Concedido_Registrado', 'Sigilo INPI'])) {
            return false;
        }
        
        // For situation 'Concedido_Registrado', we must run only if the type is one of the expected ones
        if ($situation == 'Concedido_Registrado' && !in_array($piType, ['Patente-de-Invencao', 'Modelo-de-utilidade', 'Marca', 'Protecao-Cultivar'])) {
            return false;
        }

        return true;
    }

    /**
     * This method calculates a new date by adding a period to a given date.
     * 
     * @param       string      $period          The period to add (e.g., '+1 year', '+6 months').
     * @param       string      $dtCalculation   The date to which the period will be added (format: 'Y-m-d').
     * 
     * @return      string
     */
    private function calculationDate($period, $dtCalculation)
    {
        return date('Y-m-d', strtotime($period, strtotime($dtCalculation)));
    }

    /**
     * This method validates the prices from the plugin parameters.
     * 
     * @param       string      $field          The field name to retrieve the prices from.
     * 
     * @return      array
     */
    private function validatePrices($field) 
    {
        $prices = array_map(function($item) {
            return (float) trim($item);
        }, explode(';', $this->pluginParams->get($field)));

        return $prices;
    }

    /**
     * This method sets the default situation for payments.
     * 
     */
    private function setDefaultSituationForPayments()
    {
        $params    = $this->getParams();
        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();
        $elements = $listModel->getElements('id');

        $situationElementId = $params->get('pg_element_situation');
        $situationElement  = $elements[$situationElementId];
        $this->defaultSituationForPayments = $situationElement->getDefaultValue()[0] ?? 'A Pagar';
    }

    /**
     * This method counts the number of payments for a given parent ID.
     * 
     * @param       int         $parent_id      The parent ID to filter the query.
     * 
     * @return      int
     */
    private function countQtnPayments($parent_id) 
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select('COUNT(id)')
            ->from($db->qn($this->table))
            ->where($db->qn('parent_id') . ' = ' . $db->q((int) $parent_id));
        $db->setQuery($query);

        return $db->loadResult();
    }

    /**
     * This method get the payments rows for a specific parent ID.
     * 
     * @param       int         $parent_id      The parent ID to filter the query.
     * 
     * @return      object
     */
    private function getRowsPayments($parent_id)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select($db->qn(['id', $this->getNameElementForQuery('pg_element_categories')]))
            ->from($db->qn($this->table))
            ->where($db->qn('parent_id') . ' = ' . $db->q((int) $parent_id));
        $db->setQuery($query);

        return $db->loadObjectList();
    }

    /**
     * This method sets the plugin parameters from the plugin configuration.
     * 
     * @return      void
     */
    private function setPluginParams()
    {
		$plugin  = PluginHelper::getPlugin('fabrik_form', 'paymentgenerator');
		$this->pluginParams = new Registry($plugin->params);
    }

    /**
     * This method sets the columns for the query based on table.
     * 
     * @return      void
     */
    private function setColumnsForQuery()
    {
        $this->columns = Array(
            'parent_id',
            $this->getNameElementForQuery('pg_element_categories'),
            $this->getNameElementForQuery('pg_element_start'),
            $this->getNameElementForQuery('pg_element_end_o'),
            $this->getNameElementForQuery('pg_element_end_e'),
            $this->getNameElementForQuery('pg_element_price'),
            $this->getNameElementForQuery('pg_element_situation'),
            $this->getNameElementForQuery('pg_element_alert')
        );
    }

    /**
     * This method retrieves the name of an element for a given parameter name.
     * 
     * @param       string      $paramName      The parameter name to retrieve the element name for.
     * 
     * @return      string
     */
    private function getNameElementForQuery($paramName)
    {
        $params = $this->getParams();
        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();
        $elements = $listModel->getElements('id');

        $elementId = $params->get($paramName);
        $element = $elements[$elementId];

        return $element->element->name;
    }

    /**
     * This method sets the table for the query based on the join model of the form.
     * 
     */
    private function setTableForQuery()
    {
		$joinModel = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('Join', 'FabrikFEModel');

        $formModel = $this->getModel();
        $params = $this->getParams();
        $group = $params->get('pg_group');

        $joinModel->setId($formModel->getPublishedGroups()[$group]->join_id);
        $this->table = $joinModel->getJoin()->table_join;
    }

    /**
     * This method searches for categories in a given array of sub-values that match a specified prefix.
     * 
     * @param       array       $subValues      The array of sub-values to search through.
     * @param       string      $prefix         The prefix to match against the sub-values.
     * 
     * @return      array
     */
    private function searchCategorys($subValues, $prefix)
    {
        $this->prefix = $prefix;

        return array_filter((array) $subValues, function($v, $k) {
            return stristr($v, $this->prefix);
        }, ARRAY_FILTER_USE_BOTH);
    }
    
    /**
     * This method inserts data into a specific table with the given columns and data.
     * 
     * @param       array       $data           The data to be inserted into the table.
     * 
     * @return      void
     */
    private function insertPayment($data)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $formModel = $this->getModel();
        $formData = $formModel->formData;
        $idPi = $formData['id'];

        array_unshift($data, $idPi);

        try {
            $db->transactionStart();

            $query = $db->getQuery(true);
            $query->insert($db->qn($this->table))
                ->columns($db->qn($this->columns))
                ->values(implode(',', $db->q($data)));

            $db->setQuery($query);
            $db->execute();
            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();
            Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_ERROR_DATABASE') . " - " . $e->getMessage());
        }
    }

    /**
     * This method updates rows for the Patent Grant payments.
     * 
     */
    private function updatePaymentForPatentGrant($id, $newCategory, $newValue)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $db->transactionStart();

            $query = $db->getQuery(true);
            $query->update($db->qn($this->table))
                ->set($db->qn($this->getNameElementForQuery('pg_element_categories')) . ' = ' . $db->q($newCategory))
                ->set($db->qn($this->getNameElementForQuery('pg_element_price')) . ' = ' . $db->q($newValue))
                ->where($db->qn('id') . ' = ' . (int) $id);
            $db->setQuery($query);

            $db->execute();
            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();
            Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_ERROR_DATABASE_UPDATE') . " - " . $e->getMessage());
        }
    }

    /**
     * Validate Date.
     *
     * @param       string      $date       Date to validate.
     *
     * @return      bool
     */
    private function validateDate($date)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $format = $db->getDateFormat();
            $valid  = Date::createFromFormat($format, $date);
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_INVALID_DATE_TO_GENERATE'));
            return false;
        }

        return $valid;
    }
}
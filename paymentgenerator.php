<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.textextract
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

/**
 * Run some php when the form is submitted
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.php
 * @since       3.0
 */
class PlgFabrik_FormPaymentgenerator extends PlgFabrik_Form {

    private $prefix;

    /**
     * Run right at the end of the form processing
     * form needs to be set to record in database for this to hook to be called
     *
     * @return	bool
     */
    public function onAfterProcess() {
        $params    = $this->getParams();
        $formModel = $this->getModel();

        $group     = (int) $params->get('paymentgenerator_group', 0);
        $field     = (int) $params->get('paymentgenerator_field', 0);
        $situation = $params->get('paymentgenerator_situation', '');
        $inicio    = (int) $params->get('paymentgenerator_inicio', 0);
        $fimO      = (int) $params->get('paymentgenerator_fim_o', 0);
        $fimE      = (int) $params->get('paymentgenerator_fim_e', 0);
        $alerta    = (int) $params->get('paymentgenerator_dt_alerta', 0);

        $categoryPatente    = strtoupper($params->get('paymentgenerator_category_patente', ''));
        $valuePatente       = $params->get('paymentgenerator_value_patente', '');
        $categoryMarca      = strtoupper($params->get('paymentgenerator_category_marca', ''));
        $valueMarca         = $params->get('paymentgenerator_value_marca', '');
        $categoryIndustrial = strtoupper($params->get('paymentgenerator_category_industrial', ''));
        $valueIndustrial    = $params->get('paymentgenerator_value_industrial', '');

        $id            = (int) $formModel->formData['id'];
        $formCategoria = $formModel->formData['categoria'];

        if ($group !== 0) {            
            $table = $formModel->getListModel()->getTable()->db_table_name . "_" . $group . "_repeat";

            $pluginManager = FabrikWorker::getPluginManager();
            $elementModel  = $pluginManager->getElementPlugin($field)->element;
            
            $elementField  = $elementModel->name;
            $elementInicio = $pluginManager->getElementPlugin($inicio)->element->name;
            $elementFimO   = $pluginManager->getElementPlugin($fimO)->element->name;
            $elementFimE   = $pluginManager->getElementPlugin($fimE)->element->name;
            $elementAlerta = $pluginManager->getElementPlugin($alerta)->element->name;

            $columns = array('parent_id', $elementField, $elementInicio, $elementFimO, $elementFimE, 'valor', 'situacao_pg', $elementAlerta);
            
            $quant     = $this->selectQuantityRepeats($table, $id, 'parent_id');
            $objParams = json_decode($elementModel->params);
            $subValues = $objParams->sub_options->sub_values;

            if ((int) $quant->total > 0 && (int) $quant->total <= 2) {
                if (($categoryMarca === $formCategoria[0][0] XOR (isset($formCategoria[1]) && $categoryMarca === $formCategoria[1][0])) && 
                    (stristr($formCategoria[0][0], 'PEDIDO') XOR (isset($formCategoria[1]) && stristr($formCategoria[1][0], 'PEDIDO')))) {
                    $categorys = $this->searchCategorys($subValues, 'M-');

                    // Search for the entered date corresponding to the base payment field of the calculation
                    $key          = array_search($categoryMarca, array_column($formCategoria, '0'));
                    $formDtInicio = $formModel->formData[$elementInicio][$key];
                    $formDtInicio = explode(' ', $formDtInicio)[0];

                    // Add the corresponding time interval to the category's financial control table
                    foreach ($categorys as $category) {
                        if ($category == $categoryMarca || stristr($category, 'PEDIDO')) continue; 
                        
                        if (stristr($category, '1') && stristr($category, 'PRORROGACAO')) {
                            $period        = '+9 years';
                            $dtCalculation = $formDtInicio;
                        } else {                                
                            $period        = '+10 years';
                            $dtCalculation = $dtInicio;
                        }
                        
                        $dtInicio = $this->calculationDate($period, $dtCalculation);
                        $dtFimO   = $this->calculationDate('+12 months', $dtInicio);
                        $dtFimE   = $this->calculationDate('+18 months', $dtInicio);
                        $dtAlerta = $dtInicio;

                        $data = array($id, $category, $dtInicio, $dtFimO, $dtFimE, $valueMarca, $situation, $dtAlerta);
                        
                        $this->insertData($table, $columns, $data);
                    }
                } elseif ($categoryIndustrial === $formCategoria[0][0] XOR (isset($formCategoria[1]) && $categoryIndustrial === $formCategoria[1][0])) {
                    $categorys = $this->searchCategorys($subValues, 'DI-');

                    // Search for the entered date corresponding to the base payment field of the calculation
                    $key          = array_search($categoryIndustrial, array_column($formCategoria, '0'));
                    $formDtInicio = $formModel->formData[$elementInicio][$key];
                    $formDtInicio = explode(' ', $formDtInicio)[0];

                    // Add the corresponding time interval to the category's financial control table
                    foreach ($categorys as $category) {
                        if ($category == $categoryIndustrial || stristr($category, 'CONCESSAO')) continue; 
                        
                        $dtVigencia = (stristr($category, 'DATA') && stristr($category, 'VIGENCIA'));
                        
                        if (stristr($category, '2') && stristr($category, 'QUINQUENIO')) {
                            $period        = '+4 years';
                            $dtCalculation = $formDtInicio;
                        } elseif ($dtVigencia) {
                            $period        = '+25 years';
                            $dtCalculation = $formDtInicio;
                        } else {
                            $period        = '+5 years';
                            $dtCalculation = $dtInicio;
                        }
                        
                        $dtInicio = $this->calculationDate($period, $dtCalculation);
                        $dtFimO   = ($dtVigencia) ? null : $this->calculationDate('+12 months', $dtInicio);
                        $dtFimE   = ($dtVigencia) ? null : $this->calculationDate('+18 months', $dtInicio);
                        $dtAlerta = $dtInicio;

                        $data = array($id, $category, $dtInicio, $dtFimO, $dtFimE, $valueIndustrial, $situation, $dtAlerta);

                        $this->insertData($table, $columns, $data);
                    }
                } elseif ((int) $quant->total === 1) {
                    // If you have only one payment field filled in and it is the category corresponding to the 'PATENTE DE INVENÇÃO'
                    if ($categoryPatente === $formCategoria[0][0]) {
                        $categorys = $this->searchCategorys($subValues, 'PI-');
                        
                        $formDtInicio  = $formModel->formData[$elementInicio][0];
                        $formDtInicio = explode(' ', $formDtInicio)[0];
            
                        foreach ($categorys as $category) {        
                            if ($category == $categoryPatente) continue; 
                            
                            if (stristr($category, 'EXAME') && stristr($category, 'TECNICO')) {                        
                                $period        = '+30 months';
                                $dtCalculation = $formDtInicio;
                            } elseif (stristr($category, '3') && stristr($category, 'ANUIDADE') && !stristr($category, '1')) {
                                $period        = '+24 months';
                                $dtCalculation = $formDtInicio;
                            } else {                                
                                $period        = '+12 months';
                                $dtCalculation = $dtInicio;
                            }
                            
                            $dtInicio = $this->calculationDate($period, $dtCalculation);
                            $dtFimO   = $this->calculationDate('+90 days', $dtInicio);
                            $dtFimE   = $this->calculationDate('+180 days', $dtInicio);
                            $dtAlerta = $dtInicio;
            
                            $data = array($id, $category, $dtInicio, $dtFimO, $dtFimE, $valuePatente, $situation, $dtAlerta);
    
                            $this->insertData($table, $columns, $data);
                        }
                    } else {
                        JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_2'));
                    }
                } else {
                    JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_2'));
                }
            } else {
                //JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_1'));
            }
        } else {
            JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_0'));
        }

        return true;
    }

    private function calculationDate($period, $dtCalculation)
    {
        return date('Y-m-d', strtotime($period, strtotime($dtCalculation)));
    }

    private function searchCategorys($subValues, $prefix)
    {
        $this->prefix = $prefix;

        return array_filter($subValues, function($v, $k) {
            return stristr($v, $this->prefix);
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function selectQuantityRepeats($table, $parent_id, $field) {
        $db     = JFactory::getDbo();
        $query  = $db->getQuery(true);
        
        $query
            ->select('COUNT(id) AS total')
            ->from($table)
            ->where($field . ' = ' . (int) $parent_id);

        $db->setQuery($query);

        return $db->loadObject();
    }

    private function insertData($table, $columns, $data) {
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();
            
            $query = $db->getQuery(true);
            $query
                ->insert($table)
                ->columns($columns)
                ->values(implode(',', $db->quote($data)));
            
            $db->setQuery($query);
            $db->execute();
            $db->transactionCommit();
        } catch (Exception $exc) {
            $db->transactionRollback();
            JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_3') . " - " . $exc->getMessage());
        }
    }
}

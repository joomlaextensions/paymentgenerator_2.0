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

            if ((int) $quant->total === 1) {
                // If you have only one payment field filled in and it is the category corresponding to the 'PATENTE DE INVENÇÃO'
                if ($categoryPatente === $formCategoria[0][0]) {
                    $categorys = array_filter($subValues, function($v, $k) {
                        return strstr($v, 'PI-');
                    }, ARRAY_FILTER_USE_BOTH);
                    
                    $formDtInicio  = $formModel->formData[$elementInicio][0];
                    $formDtInicio = explode(' ', $formDtInicio)[0];
        
                    foreach ($categorys as $category) {        
                        if ($category == $categoryPatente) {                        
                            continue;
                        } elseif ($category == 'PI-EXAME TECNICO') {                        
                            $dtInicio = date('Y-m-d', strtotime('+30 months', strtotime($formDtInicio)));
                        } elseif ($category == 'PI-3º ANUIDADE') {
                            $dtInicio = date('Y-m-d', strtotime('+24 months', strtotime($formDtInicio)));
                        } else {                                
                            $dtInicio = date('Y-m-d', strtotime('+12 months', strtotime($dtInicio)));
                        }
                        
                        $dtFimO   = date('Y-m-d', strtotime('+90 days', strtotime($dtInicio)));
                        $dtFimE   = date('Y-m-d', strtotime('+180 days', strtotime($dtInicio)));
                        $dtAlerta = $dtInicio;
        
                        $data = array($id, $category, $dtInicio, $dtFimO, $dtFimE, $valuePatente, $situation, $dtAlerta);

                        $this->insertData($table, $columns, $data);
                    }
                } else {
                    JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_2'));
                }
            } elseif ((int) $quant->total === 2) {
                // If you have two payment fields filled out and the corresponding category is 'MARCA' or 'DESENHO INDUSTRIAL' 
                if ($categoryMarca === $formCategoria[0][0] XOR $categoryMarca === $formCategoria[1][0]) {
                    $categorys = array_filter($subValues, function($v, $k) {
                        return strstr($v, 'M-');
                    }, ARRAY_FILTER_USE_BOTH);

                    // Search for the entered date corresponding to the base payment field of the calculation
                    $key          = array_search($categoryMarca, array_column($formCategoria, '0'));
                    $formDtInicio = $formModel->formData[$elementInicio][$key];
                    $formDtInicio = explode(' ', $formDtInicio)[0];

                    // Add the corresponding time interval to the category's financial control table
                    foreach ($categorys as $category) {
                        if ($category == $categoryMarca || $category == 'M-CONCESSAO') {                        
                            continue;
                        } elseif ($category == 'M-1º PRORROGACAO') {
                            $dtInicio = date('Y-m-d', strtotime('+9 years', strtotime($formDtInicio)));
                        } else {                                
                            $dtInicio = date('Y-m-d', strtotime('+10 years', strtotime($dtInicio)));
                        }
                        
                        $dtFimO   = date('Y-m-d', strtotime('+12 months', strtotime($dtInicio)));
                        $dtFimE   = date('Y-m-d', strtotime('+18 months', strtotime($dtInicio)));
                        $dtAlerta = $dtInicio;

                        $data = array($id, $category, $dtInicio, $dtFimO, $dtFimE, $valueMarca, $situation, $dtAlerta);
                        
                        $this->insertData($table, $columns, $data);
                    }
                } elseif ($categoryIndustrial === $formCategoria[0][0] XOR $categoryIndustrial === $formCategoria[1][0]) {
                    $categorys = array_filter($subValues, function($v, $k) {
                        return strstr($v, 'DI-');
                    }, ARRAY_FILTER_USE_BOTH);

                    // Search for the entered date corresponding to the base payment field of the calculation
                    $key          = array_search($categoryMarca, array_column($formCategoria, '0'));
                    $formDtInicio = $formModel->formData[$elementInicio][$key];
                    $formDtInicio = explode(' ', $formDtInicio)[0];

                    // Add the corresponding time interval to the category's financial control table
                    foreach ($categorys as $category) {
                        if ($category == 'DI-PEDIDO' || $category == $categoryIndustrial) {                        
                            continue;
                        } elseif ($category == 'DI-2º PER.QUINQUENIO') {
                            $dtInicio = date('Y-m-d', strtotime('+4 years', strtotime($formDtInicio)));
                        } else {
                            $dtInicio = date('Y-m-d', strtotime('+5 years', strtotime($dtInicio)));
                        }
                        
                        $dtFimO   = $category == 'DI-DATA DA VIGENCIA' ? null : date('Y-m-d', strtotime('+12 months', strtotime($dtInicio)));
                        $dtFimE   = $category == 'DI-DATA DA VIGENCIA' ? null : date('Y-m-d', strtotime('+18 months', strtotime($dtInicio)));
                        $dtAlerta = $dtInicio;

                        $data = array($id, $category, $dtInicio, $dtFimO, $dtFimE, $valueIndustrial, $situation, $dtAlerta);

                        $this->insertData($table, $columns, $data);
                    }
                } else {
                    JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_2'));
                }
            } else {
                JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_1'));
            }
        } else {
            JFactory::getApplication()->enqueueMessage(JText::_('PLG_FORM_PAYMENTGENERATOR_MESSAGE_0'));
        }

        return true;
    }

    public function selectQuantityRepeats($table, $parent_id, $field) {
        $db     = JFactory::getDbo();
        $query  = $db->getQuery(true);
        
        $query
            ->select('COUNT(id) AS total')
            ->from($table)
            ->where($field . ' = ' . (int) $parent_id);

        $db->setQuery($query);

        return $db->loadObject();
    }

    public function insertData($table, $columns, $data) {
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

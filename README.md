# Payment Generator

Este documento descreve as regras e critérios utilizados pelo plugin **Payment Generator** para determinar quando as parcelas de pagamento devem ser geradas para cada tipo de propriedade intelectual.

> **Importante:** Todo o código foi desenvolvido com base no documento elaborado pela coordenação da **PITT UFG**, disponível neste link:  
> [Tabela de critérios – PITT UFG](https://docs.google.com/spreadsheets/d/1Jim7GqIhSEWS7KZcK7ug10tb4j62oSDUvIb3d048y28/edit?pli=1&gid=0#gid=0)

## 📜 Visão Geral

O plugin processa os dados do formulário de PI para verificar se é necessário agendar uma nova parcela de pagamento.  
Cada tipo de propriedade intelectual possuem regras específicas para a geração dos pagamentos.

Os tipos de PI contemplados na geração de pagamentos são as seguintes:

- **Patente de invenção**
- **Modelo de utilidade**
- **Desenho industrial**
- **Marca**
- **Proteção de cultivar**

Os valores das parcelas são configurados nos parâmetros do plugin, na administração do Joomla!, nos seguintes campos:

- **Valores patente de invenção (Pedido)**
- **Valores patente de invenção (Carta de patente)**
- **Valores modelo de utilidade (Pedido)**
- **Valores modelo de utilidade (Carta de patente)**
- **Valores marcas**
- **Valores desenho industrial**
- **Valores proteção de cultivares**

Os valores devem ser informados como **lista separada por vírgulas**, onde cada item representa o valor de uma parcela.

> **Importante:** Se o último valor for repetido, informar apenas uma vez.

> **Importante:** Os seguintes tipos de PI não geram pagamentos automaticamente:
> - Programa de computador
> - Origem de cultivar
> - Topografia de circuito integrado
> - Indicação geográfica

## 🧩 Critérios de Checagem

### ⚙️ Critérios Gerais (para o plugin executar a geração de pagamentos)

- Deve ser uma edição de formulário
- A situação da PI deve ser 'Pedido de proteção depositado' ou 'Concedido/Registrado'
- Para os casos das PIs com situação 'Concedido/Registrado' elas devem ser do tipo 'Patente de invenção', 'Modelo de utilidade' ou 'Marca'
- O campo **Categoria** deve estar preenchido com pelo menos um dos valores, 'Taxa de depósito' ou 'Taxa de pedido'.
- O campo **Início** deve estar preenchido.
- As regras específicas de cada tipo de PI devem ser atendidas (veja abaixo).

### ⚙️ Critérios Específicos por Tipo de Propriedade Intelectual

### 1. **Patente de Invenção – Pedido**
- Haver apenas um pagamento presente **(Taxa de depósito)**
- Adiciona-se todos os critérios gerais **citados acima**

### 2. **Patente de Invenção – Carta de Patente**
- Não houver outros pagamentos já contemplando alguma categoria de **Carta de patente (CP)**
- Adiciona-se todos os critérios gerais **citados acima**

### 3. **Modelo de Utilidade – Pedido**
- Haver apenas um pagamento presente **(Taxa de depósito)**
- Adiciona-se todos os critérios gerais **citados acima**

### 4. **Modelo de Utilidade – Carta de Patente**
- Não houver outros pagamentos já contemplando alguma categoria de **Carta de patente (CP)**
- Adiciona-se todos os critérios gerais **citados acima**

### 5. **Marcas - Pedido**
- Haver dois ou mais pagamentos presentes **(M-TAXA DE PEDIDO e M-TAXA DE CONCESSÃO)**
- Estar presente um pagamento com a categoria **Taxa de concessão**
- Adiciona-se todos os critérios gerais **citados acima**

### 6. **Marcas - Carta de Patente**
- Haver dois ou mais pagamentos presentes **(M-TAXA DE PEDIDO e M-TAXA DE CONCESSÃO)**
- Não haver um pagamento com categoria **M-2ª PRORROGACAO** (entende-se que já foi gerado os pagamentos)
- Adiciona-se todos os critérios gerais **citados acima**

### 7. **Desenho Industrial**
- Haver apenas um pagamento presente
- Adiciona-se todos os critérios gerais **citados acima**

### 8. **Proteção de Cultivares**
- Haver dois ou mais pagamentos presentes **(C-TAXA DE PEDIDO e C-CERTIFICADO)**
- Não haver um pagamento com categoria **C-CERTIFICADO** (entende-se que já foi gerado os pagamentos)
- Adiciona-se todos os critérios gerais **citados acima**

## 📌 Observações
- Todas as checagens são baseadas nos **dados do formulário** e **dados originais** recebidos pelo plugin.
- O plugin só gera pagamentos quando todas as condições obrigatórias para o tipo de propriedade intelectual forem atendidas.
- É obrigatório configurar os valores de cada tipo antes de utilizar o recurso.

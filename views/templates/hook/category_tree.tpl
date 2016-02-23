{**
* 2014 Easymarketing AG
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to info@easymarketing.de so we can send you a copy immediately.
*
* @author    silbersaiten www.silbersaiten.de <info@silbersaiten.de>
* @copyright 2014 Easymarketing AG
* @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
*}
<table cellspacing="0" cellpadding="0" class="table">
    <tr>
	<th>{l s='Root' mod='easymarketing'}</th>
	<th>
		<input type="checkbox" name="checkme" class="noborder" onclick="processCheckBoxes(this.checked)" />
	</th>
	<th>{l s='ID' mod='easymarketing'}</th>
	<th>{l s='Name' mod='easymarketing'}</th>
	<th>{l s='Google Category Name' mod='easymarketing'}</th>
    </tr>
    {foreach from=$categories item=category}
	<tr class="{if $category.index % 2 == 0}alt_row{else}{/if}">
	    <td>
		<input type="radio" name="categoryRoot" class="categoryBox" id="categoryRoot_{$category.current|escape:'htmlall':'UTF-8'}" value="{$category.current|escape:'htmlall':'UTF-8'}"{if $category.rootCategory} checked="checked"{/if} />
	    </td>
	    <td>
		<input type="checkbox" name="categoryBox[{$category.current|escape:'htmlall':'UTF-8'}][id_category]" class="categoryBox{if $category.id_category_default == $category.current} id_category_default{/if}" id="categoryBox_{$category.current|escape:'htmlall':'UTF-8'}" value="{$category.current|escape:'htmlall':'UTF-8'}" {if $category.selected}checked="checked"{/if} />
	    </td>
	    <td>
		{$category.current|escape:'htmlall':'UTF-8'}
	    </td>
	    <td>
		{for $i = 2 to $category.level|@count}
		    {$i|escape:'htmlall':'UTF-8'} - {$category.has_suite[$i - 2]|escape:'htmlall':'UTF-8'}
		    <img  src="../modules/easymarketing/views/img/lvl_{$category.has_suite[$i - 2]|escape:'htmlall':'UTF-8'}.gif" alt="" />
		{/for}
	
		<img src="../modules/easymarketing/views/img/{if $category.level == 1}lv1.gif{else}lv2_{if $category.todo == $category.doneC}f{else}b{/if}.gif{/if}" alt="" /> &nbsp;
		<label for="categoryBox_{$category.current|escape:'htmlall':'UTF-8'}" class="t">{$category.category_name|escape:'htmlall':'UTF-8'}</label>
	    </td>
	    <td>
		<input type="text" name="categoryBox[{$category.current|escape:'htmlall':'UTF-8'}][name]" value="{$category.category_name_raw|escape:'htmlall':'UTF-8'}" />
		<input type="hidden" name="categoryLevel[{$category.current|escape:'htmlall':'UTF-8'}]" value="{$category.level|escape:'htmlall':'UTF-8'}" />
		<input type="hidden" name="categoryAllChildren[{$category.current|escape:'htmlall':'UTF-8'}]" value="{$category.all_children|escape:'htmlall':'UTF-8'}" />
		<input type="hidden" name="categoryChildren[{$category.current|escape:'htmlall':'UTF-8'}]" value="{$category.children|escape:'htmlall':'UTF-8'}" />
	    </td>
	</tr>
    {/foreach}
</table>
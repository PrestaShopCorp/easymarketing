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
		<input type="radio" name="categoryRoot" class="categoryBox" id="categoryRoot_{$category.current}" value="{$category.current}"{if $category.rootCategory} checked="checked"{/if} />
	    </td>
	    <td>
		<input type="checkbox" name="categoryBox[{$category.current}][id_category]" class="categoryBox{if $category.id_category_default == $category.current} id_category_default{/if}" id="categoryBox_{$category.current}" value="{$category.current}" {if $category.selected}checked="checked"{/if} />
	    </td>
	    <td>
		{$category.current}
	    </td>
	    <td>
		{for $i = 2 to $category.level|@count}
		    {$i} - {$category.has_suite[$i - 2]}
		    <img  src="../modules/easymarketing/img/lvl_{$category.has_suite[$i - 2]}.gif" alt="" />
		{/for}
	
		<img src="../modules/easymarketing/img/{if $category.level == 1}lv1.gif{else}lv2_{if $category.todo == $category.doneC}f{else}b{/if}.gif{/if}" alt="" /> &nbsp;
		<label for="categoryBox_{$category.current}" class="t">{$category.category_name}</label>
	    </td>
	    <td>
		<input type="text" name="categoryBox[{$category.current}][name]" value="{$category.category_name_raw}" />
		<input type="hidden" name="categoryLevel[{$category.current}]" value="{$category.level}" />
		<input type="hidden" name="categoryAllChildren[{$category.current}]" value="{$category.all_children}" />
		<input type="hidden" name="categoryChildren[{$category.current}]" value="{$category.children}" />
	    </td>
	</tr>
    {/foreach}
</table>
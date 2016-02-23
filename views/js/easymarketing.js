/**
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
 */

$(document).ready(function() {
    window.root_category = 0;
    $('input[name^=categoryBox]').attr('disabled', 'disabled');
    $('input[name^=checkme]').attr('disabled', 'disabled');

    if ($('input[name=categoryRoot]:checked').val() != null) {
        window.root_category = $('input[name=categoryRoot]:checked').val();
        allchildren = $('input[name="categoryAllChildren['+window.root_category+']"]').val().split(';');
        children = $('input[name="categoryChildren['+window.root_category+']"]').val().split(';');

        $('input[name^=checkme]').removeAttr('disabled');
        $('input[name="categoryBox['+window.root_category+'][name]"]').removeAttr('disabled');
        $('input[name="categoryBox['+window.root_category+'][id_category]"]').removeAttr('disabled');
        $('input[name="categoryBox['+window.root_category+'][id_category]"]').attr('checked', 'checked');
        $.each(allchildren, function(index, value) {
            $('input[name="categoryBox['+value+'][name]"]').removeAttr('disabled');
            $('input[name="categoryBox['+value+'][id_category]"]').removeAttr('disabled');
        });
    }

    $("input[name^=categoryRoot]").on('click', function() {
        window.root_category = $('input[name=categoryRoot]:checked').val();

        $('input[name^=categoryBox]').attr('disabled', 'disabled');
        $('input[name^=checkme]').removeAttr('disabled');
        $("input[name^=categoryBox]:disabled").removeAttr("checked");

        allchildren = $('input[name="categoryAllChildren['+window.root_category+']"]').val().split(';');
        children = $('input[name="categoryChildren['+window.root_category+']"]').val().split(';');

        $('input[name="categoryBox['+window.root_category+'][name]"]').removeAttr('disabled');
        $('input[name="categoryBox['+window.root_category+'][id_category]"]').removeAttr('disabled');
        $('input[name="categoryBox['+window.root_category+'][id_category]"]').attr('checked', 'checked');
        $.each(children, function(index, value) {
            $('input[name="categoryBox['+value+'][name]"]').removeAttr('disabled');
            $('input[name="categoryBox['+value+'][id_category]"]').removeAttr('disabled');
        });
    });

    $("input[name^=categoryBox]:checkbox").on('click', function(){
        if($(this).is(":checked")) {
            children = $('input[name="categoryChildren['+$(this).val()+']"]').val().split(';');
            $.each(children, function(index, value) {
                $('input[name="categoryBox['+value+'][name]"]').removeAttr('disabled');
                $('input[name="categoryBox['+value+'][id_category]"]').removeAttr('disabled');
            });
        } else {
            allchildren = $('input[name="categoryAllChildren['+$(this).val()+']"]').val().split(';');
            $.each(allchildren, function(index, value) {
                $('input[name="categoryBox['+value+'][name]"]').attr('disabled', 'disabled');
                $('input[name="categoryBox['+value+'][id_category]"]').attr('disabled','disabled');
                $('input[name="categoryBox['+value+'][id_category]"]').removeAttr('checked');
            });
        }
        $('input[name="categoryBox['+window.root_category+'][id_category]"]').attr('checked', 'checked');
    });



});

function processCheckBoxes(currentValue) {
     window.root_category = $('input[name^=categoryBox]:checkbox:first').val();
     if (currentValue) {

        //$('input[name^=categoryBox]').attr('disabled', 'disabled');
        //$("input[name^=categoryBox]:disabled").removeAttr("checked");

        $("input[name^=categoryRoot]:first").trigger('click');
        $('input[name^=categoryBox]').removeAttr('disabled');
        $('input[name^=categoryBox]:checkbox').attr('checked', 'checked');
    } else {
         $('input[name^=categoryBox]:checkbox').removeAttr('checked');
         $('input[name^=categoryBox]').attr('disabled', 'disabled');
         $("input[name^=categoryRoot]:first").trigger('click');
    }
}
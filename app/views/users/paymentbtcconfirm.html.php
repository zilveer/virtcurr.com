<?php
//print_r($transaction);
?>
<h4>Withdraw <?=abs($transaction['Amount'])?> BTC to <?=$transaction['address']?></h4>
<form action="/users/payment/" method="post">
<input type="hidden" name="username" id="username" value="<?=$transaction['username']?>"/>
<input type="hidden" id="verify" value="<?=$transaction['verify.payment']?>" name="verify">
<?=$this->form->field('password', array('type' => 'password', 'label'=>'Password', 'placeholder'=>'password')); ?>
<input type="submit" value="Confirm BTC Withdrawal" class="btn btn-success"> 
</form>
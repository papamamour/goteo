<?php

$this->layout('admin/channelsection/layout');

$this->section('admin-search-box-addons');
?>


<?php $this->replace() ?>

<?php $this->section('admin-container-body') ?>

<div>
    <select id="nodes-filter" name="nodes-list" class="form-control" style="margin-bottom:1em;" onchange="gochannelSection()">
        <?php if (!$this->current_node) : ?>
        <option selected="selected" hidden></option>
        <?php endif; ?>
        <?php foreach ($this->nodes as $nodeId => $nodeName) : ?>
        <option value="<?php echo $nodeId; ?>" <?php if ($nodeId == $this->current_node) echo 'selected="selected"'; ?>><?php echo $nodeName; ?></option>
        <?php endforeach; ?>
    </select>
</div>

<a class="btn btn-cyan" href="/admin/channelsection/<?= $this->current_node ?>/add"><i class="fa fa-plus"></i> <?= $this->text('admin-channelsection-add') ?></a>

<h5><?= $this->text('admin-list-total', $this->total) ?></h5>

<?= $this->insert('admin/partials/material_table', ['list' => $this->model_list_entries($this->list, ['id', 'section', 'order', 'actions'])]) ?>

</div>
</div>



<?php $this->replace() ?>

<?php $this->section('footer') ?>
<script type="text/javascript">
    function gochannelSection() {
        var selected = document.getElementById("nodes-filter").value;
        window.location = "/admin/channelsection/" + selected;
    }

</script>
<?php $this->append() ?> 
<?php
use Goteo\Library\Text,
    Goteo\Core\ACL;

$translator = ACL::check('/translate') ? true : false;
?>
<a href="/admin/blog/add" class="button red">Nueva entrada</a>
&nbsp;&nbsp;&nbsp;
<a href="/admin/blog/reorder" class="button aqua">Ordenar la portada</a>
<?php if (empty($_SESSION['admin_node'])) : ?>
&nbsp;&nbsp;&nbsp;
<a href="/admin/blog/footer" class="button aqua">Ordenar el footer</a>
<?php endif; ?>

<div class="widget board">
    <?php if (!empty($this['posts'])) : ?>
    <table>
        <thead>
            <tr>
                <th colspan="4">Título</th> <!-- title -->
                <th>Fecha</th> <!-- date -->
                <th><!-- published --></th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($this['posts'] as $post) : ?>
            <tr>
                <td colspan="4"><?php
                        if ($post->home)
                            $style = ' font-weight:bold;';
                        if (empty($_SESSION['admin_node']) || $_SESSION['admin_node'] == \GOTEO_NODE) {
                            if ($post->footer)
                                $style .= ' font-style:italic;';
                        }
                            
                      echo "<span style=\"{$style}\">{$post->title}</span>";
                ?></td>
                <td><?php echo $post->date; ?></td>
                <td><?php if ($post->publish) echo 'Publicada'; ?></td>
            </tr>
            <tr>
                <td><a href="/blog/<?php echo $post->id; ?>?preview=<?php echo $_SESSION['user']->id ?>" target="_blank">[Ver publicado]</a></td>
                <td><a href="/admin/blog/edit/<?php echo $post->id; ?>">[Editar]</a></td>
                <td><?php
                        if (isset($this['home'][$post->id]))
                            echo '<a href="/admin/blog/remove_home/'.$post->id.'" style="color:red;">[Quitar de portada]</a>';
                        else
                            echo '<a href="/admin/blog/add_home/'.$post->id.'" style="color:blue;">[Poner en portada]</a>';
                ?></td>
                <td><?php
                    if (empty($_SESSION['admin_node']) || $_SESSION['admin_node'] == \GOTEO_NODE) {
                        if (isset($this['footer'][$post->id]))
                            echo '<a href="/admin/blog/remove_footer/'.$post->id.'" style="color:red;">[Quitar del footer]</a>';
                        else
                            echo '<a href="/admin/blog/add_footer/'.$post->id.'" style="color:blue;">[Poner en footer]</a>';
                    }
                ?></td>
                <td><?php if ($translator) : ?><a href="/translate/post/edit/<?php echo $post->id; ?>" >[Traducir]</a><?php endif; ?></td>
                <td><?php if (!empty($_SESSION['admin_node']) && $post->owner == 'node-'.$_SESSION['admin_node']) : ?>
                    <a href="/admin/blog/remove/<?php echo $post->id; ?>" onclick="return confirm('Seguro que deseas eliminar este registro?');">[Eliminar]</a>
                <?php endif; ?></td>
            </tr>
            <tr>
                <td colspan="6"><hr /></td>
            </tr>
            <?php endforeach; ?>
        </tbody>

    </table>
    <?php else : ?>
    <p>No se han encontrado registros</p>
    <?php endif; ?>
</div>
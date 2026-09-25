<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Repositories\CategoryRepository;

final class CategoryController extends Controller
{
    public const ICONS = ['tag', 'basket', 'cart', 'house', 'car-front', 'fuel-pump', 'bus-front', 'train-front', 'bicycle', 'airplane',
        'shield-check', 'heart-pulse', 'capsule', 'controller', 'film', 'music-note', 'book', 'mortarboard', 'bag', 'gift', 'cup-hot',
        'egg-fried', 'droplet', 'lightning-charge', 'fire', 'wifi', 'phone', 'laptop', 'tv', 'tools', 'hammer', 'tree', 'flower1',
        'paw', 'people', 'person', 'balloon', 'trophy', 'bank', 'piggy-bank', 'cash-stack', 'credit-card', 'receipt', 'percent',
        'plus-circle', 'dash-circle', 'three-dots', 'scissors', 'brush', 'briefcase', 'globe', 'sun', 'umbrella', 'wrench', 'emoji-smile'];

    public function index(): void
    {
        $this->view('categories/index', [
            'title' => 'Kategorien',
            'tree'  => (new CategoryRepository())->tree($this->hid),
            'icons' => self::ICONS,
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function store(): void
    {
        $repo = new CategoryRepository();
        $data = $this->validated($repo);
        if ($data['name'] === '') {
            $this->back('/categories', 'Bitte einen Namen angeben.');
        }
        $repo->create($this->hid, $data);
        $this->redirect('/categories', 'Kategorie „' . $data['name'] . '“ angelegt.');
    }

    public function update(int $id): void
    {
        $repo = new CategoryRepository();
        $cat = $repo->find($id, $this->hid) ?? $this->notFound();
        $data = $this->validated($repo);
        if ($data['parent_id'] === $id) {
            $data['parent_id'] = $cat['parent_id'];
        }
        // Hauptkategorie mit Unterkategorien kann nicht selbst Unterkategorie werden
        if ($data['parent_id'] && !$cat['parent_id'] && $this->hasChildren($id)) {
            $data['parent_id'] = null;
        }
        $repo->update($id, $this->hid, $data);
        $this->redirect('/categories', 'Kategorie gespeichert.');
    }

    public function delete(int $id): void
    {
        $repo = new CategoryRepository();
        $cat = $repo->find($id, $this->hid) ?? $this->notFound();
        $target = $repo->validId($this->request->int('move_to'), $this->hid);
        if ($target === $id) {
            $target = null;
        }
        $repo->transaction(function () use ($repo, $id, $cat, $target) {
            $repo->reassign($id, $target ?? ($cat['parent_id'] ? (int) $cat['parent_id'] : null));
            $repo->delete($id, $this->hid);
        });
        $this->redirect('/categories', 'Kategorie gelöscht.');
    }

    private function validated(CategoryRepository $repo): array
    {
        $r = $this->request;
        $parent = $repo->validId($r->int('parent_id'), $this->hid);
        $type = $r->str('type') === 'income' ? 'income' : 'expense';
        if ($parent) {
            $p = $repo->find($parent, $this->hid);
            if ($p['parent_id']) {
                $parent = (int) $p['parent_id']; // nur zwei Ebenen
            }
            $type = $p['type'];
        }
        return [
            'name'      => mb_substr($r->str('name'), 0, 100),
            'parent_id' => $parent,
            'type'      => $type,
            'icon'      => in_array($r->str('icon'), self::ICONS, true) ? $r->str('icon') : 'tag',
            'color'     => preg_match('/^#[0-9a-f]{6}$/i', $r->str('color')) ? $r->str('color') : '#6c757d',
        ];
    }

    private function hasChildren(int $id): bool
    {
        foreach ((new CategoryRepository())->all($this->hid) as $c) {
            if ((int) $c['parent_id'] === $id) {
                return true;
            }
        }
        return false;
    }
}

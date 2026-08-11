<div class="mb-3">
    <label for="name" class="form-label">商品名</label>
    <input type="text" class="form-control" id="name" name="name"
           value="{{ old('name', $product->name ?? '') }}">
    @error('name')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="price" class="form-label">価格（円）</label>
    <input type="number" class="form-control" id="price" name="price" min="0"
           value="{{ old('price', $product->price ?? '') }}">
    @error('price')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="stock" class="form-label">在庫数</label>
    <input type="number" class="form-control" id="stock" name="stock" min="0"
           value="{{ old('stock', $product->stock ?? 0) }}">
    @error('stock')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="category" class="form-label">カテゴリー</label>
    <select class="form-select" id="category" name="category">
        <option value="">選択してください</option>
        @foreach (App\Models\Product::CATEGORIES as $category)
            <option value="{{ $category }}"
                @selected(old('category', $product->category ?? '') === $category)>
                {{ $category }}
            </option>
        @endforeach
    </select>
    @error('category')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">説明（任意）</label>
    <textarea class="form-control" id="description" name="description" rows="6">{{ old('description', $product->description ?? '') }}</textarea>
    @error('description')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<button type="submit" class="btn btn-primary">{{ $submitLabel ?? '保存' }}</button>

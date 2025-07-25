const W2 = function () {
    class W2Error extends Error{

    }

    class W2HtmlUtils {
        static clearContent(node){
            while(node.firstChild){
                node.removeChild(node.firstChild)
            }
        }

        static getExistingNode(info){
            if(info instanceof Element || info instanceof Text){
                return info
            } else if(info instanceof String || typeof info === "string"){
                const node = document.getElementById(info)

                if(node) return node
            } else if(info.domNode && this.isDOMNode(info.domNode)){
                return info.domNode
            }

            throw new W2Error("Could not find element!")
        }

        static isDOMNode(node){
            return node instanceof Element || node instanceof Text || node instanceof DocumentFragment || node instanceof Comment
        }
    }

    class W2HtmlNode {
        static new(data){
            let node
            if(W2HtmlUtils.isDOMNode(data)){
                node = data
            } else if (data.domNode && W2HtmlUtils.isDOMNode(data.domNode)){
                node = data.domNode
            } else if(data instanceof String || typeof data === "string"){
                node = document.createElement(data)
            } else if(data instanceof Function){
                return W2HtmlNode.new(data())
            } else if(!data){
                node = document.createDocumentFragment()
            } else {
                throw new W2Error(`Could not create element from type '${typeof data}'!`)
            }

            return new W2HtmlNode(node)
        }

        static empty(){
            return new W2HtmlNode(document.createDocumentFragment())
        }

        domNode

        constructor(domNode) {
            this.domNode = domNode
        }

        ref(ref){
            ref.current = this.domNode

            return this
        }

        content(...args){
            for (const arg of args) {
                if(W2HtmlUtils.isDOMNode(arg)){
                    this.#addChildNode(arg)
                } else if (arg.domNode && W2HtmlUtils.isDOMNode(arg.domNode)){
                    this.#addChildNode(arg.domNode)
                } else if(arg instanceof String || typeof arg === "string"){
                    this.#addChildNode(document.createTextNode(arg))
                } else if(arg instanceof Number || typeof arg === "number"){
                    this.#addChildNode(document.createTextNode(arg.toString()))
                } else if(arg instanceof Function || typeof arg === "function"){
                    arg(this)
                } else if(arg instanceof Array){
                    this.content(...arg)
                } else if(arg && arg.toString) {
                    this.#addChildNode(document.createTextNode(arg.toString()))
                }
            }

            return this
        }

        contentIf(condition,...args){
            if(condition){
                this.content(...args)
            }

            return this
        }

        class(...names){
            if (this.domNode instanceof Element){
                for (const name of names) {
                    const classes = name.split(" ")
                    for (const clazz of classes) {
                        this.domNode.classList.add(clazz)
                    }
                }
            }

            return this
        }

        classIf(condition, ...args){
            if(condition){
                this.class(...args)
            }

            return this
        }


        style(property, value){
            this.domNode.style.setProperty(property,value)

            return this
        }

        styleIf(condition, property, value){
            if(condition) {
                this.domNode.style.setProperty(property, value)
            }

            return this
        }

        attribute(name,value){
            this.domNode.setAttribute(name,value)

            return this
        }

        attributeIf(condition, name, value){
            if(condition){
                this.attribute(name, value)
            }

            return this
        }

        id(id){
            this.attribute("id",id)

            return this
        }

        addInto(target){
            const node = W2HtmlUtils.getExistingNode(target)

            node.appendChild(this.domNode)

            return this
        }

        event(name,callback){
            this.domNode.addEventListener(name,callback)
            return this
        }

        #addChildNode(node){
            this.domNode.appendChild(node)
        }
    }

    class W2Mount {
        #render
        #anchor
        #last
        domNode
        state

        constructor(render,state) {
            if(state instanceof W2MountState){
                state.setMount(this)
            }

            this.#render = render
            this.state = state

            this.#anchor = document.createComment("mount anchor")

            const root = W2HtmlNode.empty()
            this.#render(root,this,this.state)

            this.#last = root.domNode.lastChild
            this.domNode = W2HtmlNode.empty().content(this.#anchor).content(root).domNode
        }

        #removeContent(){
            if(this.#last) {
                let currentNode = this.#anchor.nextSibling
                while (currentNode) {
                    currentNode.parentNode.removeChild(currentNode)

                    if (currentNode === this.#last) break

                    currentNode = this.#anchor.nextSibling
                }
            }
        }

        update(){
            restoreScrollPosition(()=>{
                //remove old content
                this.#removeContent()

                const newTree = W2HtmlNode.empty()
                this.#render(newTree, this, this.state)

                this.#last = newTree.domNode.lastChild

                if (this.#anchor.parentNode){
                    this.#anchor.parentNode.insertBefore(newTree.domNode,this.#anchor.nextSibling)
                }
            })
        }

        unmount(){
            this.#removeContent()
            //remove anchor
            this.#anchor.parentNode.removeChild(this.#anchor)
        }

        addInto(target){
            const node = W2HtmlUtils.getExistingNode(target)

            node.appendChild(this.domNode)

            return this
        }
    }

    const mount = (a,b)=>{
        let state, render

        if(typeof a === "function"){
            render = a
            state = b || {}
        } else {
            render = b
            state = a
        }

        return new W2Mount(render,state)
    }

    class W2MountState {
        #mount

        setMount(mount){
            this.#mount = mount
        }

        stateChanged(){
            if(this.#mount){
                this.#mount.update()
            }
        }
    }

    class W2Component {

        #mount

        stateChanged(){
            if(this.#mount){
                this.#mount.update()
            }
        }

        mount(){
            this.#mount = mount((...args)=>this.render(...args))
            return this.#mount
        }

        render(){
            throw W2Error("You need to implement the render method on a component!")
        }
    }

    const tempIDMap = {}
    let idCounter = 0
    function getID(name,createNew) {
        if(!createNew){
            const id = tempIDMap[name]
            if (id) return id
        }

        const id = `w2id_${name}_${idCounter++}`
        tempIDMap[name] = id
        return  id
    }

    function restoreScrollPosition(action,element=null){
        if(!element){
            element = document.body
        }

        const position = element.scrollTop
        action()
        element.scrollTop = position
    }

    function useRef(initial=undefined) {
        return {
            current: initial
        }
    }

    return {
        html: W2HtmlNode.new,
        emptyHtml: W2HtmlNode.empty,
        mount,
        W2HtmlUtils,
        W2Error,
        W2Component,
        W2MountState,
        getID,
        restoreScrollPosition,
        useRef
    }
}()
